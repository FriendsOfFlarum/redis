# Distributed Cache Invalidation

## Overview

When running multiple Flarum instances (pods/containers) with Redis cache, cache clearing on one instance needs to be propagated to all other instances. This feature automatically handles distributed cache invalidation.

## How It Works

### The Problem

When cache-affecting admin actions run — clearing cache via admin panel or `php flarum cache:clear`, enabling/disabling an extension, or saving settings:
1. **Pod A** clears its Redis cache (shared) ✅
2. **Pod A** clears its file caches (local) ✅
3. **Pods B, C, D** still have stale file caches ❌

This causes missing translations (raw `core.*` keys), stale assets, and other cache-related issues. Extension toggles and settings saves are especially insidious: Flarum core reacts to them with pod-local invalidation only and never dispatches `ClearingCache`, so without propagation the other pods stay stale until the next explicit cache clear.

> **Not covered by propagation:** raw *English* core keys (`=> core.…`) appearing right after a catalogue rebuild are a separate Flarum core bug on 1.x — the hardcoded English fallback catalogue skips reference resolution when it is loaded implicitly (flarum/framework#5024; fixed in 2.x by #5023). Propagation cannot fix it, and because every pod now rebuilds its catalogues after an admin action, the bug can surface on any pod rather than only the acting one. Until core is patched, warm the English catalogue early in the request (`resolve('translator')->getCatalogue('en')`) from a site extender.

### The Solution

**Automatic propagation using Redis Pub/Sub:**

1. When an invalidating action happens on Pod A:
   - `cache:clear` (CLI or admin Clear Cache button)
   - an extension is enabled or disabled
   - settings are saved in the admin panel
   Pod A performs its local invalidation and publishes a cache invalidation message to the Redis channel

2. Pod B subscriber receives the message:
   - Invalidates local file caches immediately

3. **Epoch backstop (synchronous):** Pod A also bumps a shared epoch value in Redis (`flarum:cache:version`). Before serving a request, each pod compares that epoch with the one it last applied (recorded in a pod-local file) and clears its local caches first when behind. This covers what pub/sub alone cannot:
   - a request racing the asynchronous message delivery (it can no longer rebuild shared assets from stale local state)
   - a message published while a pod's subscriber was down (pub/sub has no replay — the epoch is durable)

4. All pods stay synchronized: pub/sub is the fast path, the epoch check is the correctness guarantee

### What Gets Invalidated

An apply forgets cache entries rather than deleting the files they reference, with one exception (the locale catalogues):

- the file cache (`storage/cache`) — on 1.x its only core tenant is the serialized TextFormatter entry; core's own `Formatter::flush()` forgets just that key
- `storage/locale/*` — the compiled Symfony catalogues. These are the only files an apply deletes: their names are not content-derived, so nothing else would detect staleness. The deletion has the same microsecond window (`is_file()` → `include`) that core's own `Extend\Locales` has on the acting pod. Their OPcache entries are invalidated too, as belt-and-braces — Symfony re-invalidates a catalogue when it rewrites it, and from the CLI subscriber the call is a no-op
- the shared settings cache (`flarum:settings`) and the resolved settings repository instance

Deliberately **not** touched:

- `storage/formatter/*` — the generated renderer classes. This matches core's own runtime refresh (`Formatter::flush()` and the Formatter extender only forget the cache entry; `cache:clear` sweeps the files). The renderer class is autoloaded from its file *during* `unserialize()`; deleting it while another request is unserializing the cached formatter (a microsecond window) leaves that request with an incomplete object, and a method call on it throws `\Error`, which core's render guard does not catch. Residual, stated plainly: the class name is a hash of the generated code, so a rebuild after a config-neutral toggle rewrites the *same* file in place, non-atomically, and every concurrent request that misses the cache rebuilds it (on a `storage/` volume shared between pods, from every pod) — OPcache normally serves the cached copy across that window
- `storage/views/*` — core deletes these on the acting pod when an extension that registers views is toggled, but other pods have nothing to fix: compiled views are keyed by the resolved source path and expire by source mtime, so they can only be stale if a template *source* changed, which toggles and settings saves never do
- the shared compiled frontend assets and `rev-manifest.json` — core flushes those itself, on the pod handling the admin action (a single actor, though each flush is a dozen or so sequential writes of the manifest)

## Architecture

### Components

1. **Publisher** (Redis)
   - Channel: `flarum:cache:invalidate`
   - Message: JSON with `timestamp`, `source`, `version`

2. **Subscriber** (`cache:subscribe` command)
   - Subscribes to the channel
   - Clears local caches immediately

### Performance

Pub/Sub itself has near-zero per-request overhead. The epoch backstop adds one Redis `GET` per request by default (sub-millisecond; this extension already performs a Redis GET per request for the settings cache). Set `check_interval` to throttle the check per pod — the throttle uses APCu when available; without APCu the check runs on every request regardless.

The epoch record is written to `<flarum root>/cache-epoch-<hostname>-<sapi>` — the base path must be writable by the web user. If it is not, the backstop disables itself and logs a warning (it never loops). The hostname suffix keeps records per pod even when the install root is a shared volume; the SAPI suffix lets php-fpm perform its own apply (OPcache is per process pool, so a CLI subscriber's invalidation does not reach php-fpm's). Now that the apply no longer resets OPcache globally, php-fpm's second apply buys little; collapsing the two is a possible follow-up.

An apply itself is not free. It drops the serialized formatter, so the next post-rendering requests on that pod rebuild it (concurrently — there is no lock), and it deletes the compiled catalogues, so the next requests recompile them. Every settings save triggers a full apply on every pod, although core itself reacts to a non-theme save with nothing pod-local; narrowing the `Saved` apply to the settings-cache forget it actually needs is a planned follow-up.

## Configuration

### Pub/Sub Configuration

Enable Pub/Sub auto-start and configure the channel via the Redis extender config:

```php
return [
    (new FoF\Redis\Extend\Redis([
        'host' => '127.0.0.1',
        'password' => null,
        'port' => 6379,
        'database' => 1,
        'pubsub' => [
            'enabled' => true,
            'autostart' => true,
            'channel' => 'flarum:cache:invalidate',
            'delay' => 0,
            'spawn_lock_ttl' => 300,
            // Seconds between per-worker epoch checks in the request middleware.
            // 0 (default) checks on every request — one Redis GET, sub-millisecond.
            'check_interval' => 0,
        ],
        // Where compiled-asset revisions are stored (see "Asset revisions in
        // Redis" below): 'auto' (default — Redis when pub/sub is enabled,
        // otherwise core's rev-manifest.json), 'redis', or 'file'.
        'asset_revisions' => 'auto',
    ]))
];
```

## Requirements

### Required
- fof/redis installed and configured
- Redis connection available
- Multiple Flarum instances

### No Additional Requirements
- No special PHP extensions needed

## Deployment

### Enable Pub/Sub

1. Install/upgrade fof/redis
2. Enable `pubsub` config (see above)
3. Clear cache: `php flarum cache:clear`

### Testing

**Verify it's working:**

```bash
# On Pod A - clear cache
php flarum cache:clear

# On Pod B - check logs for subscriber message
docker logs <container> | grep "Cache Subscriber"

# Check Pod B's locale cache was cleared
ls -la storage/locale/
# Should be empty or regenerated
```

## Troubleshooting

### Cache not propagating?

**Check Redis connection:**
```bash
php flarum tinker
>>> resolve(\Illuminate\Contracts\Redis\Factory::class)->connection('fof.cache')->ping();
```


### Manual invalidation for testing

```php
// Force all instances to invalidate immediately
php flarum tinker
>>> resolve(\Illuminate\Contracts\Redis\Factory::class)->connection('fof.cache')->publish('flarum:cache:invalidate', json_encode(['timestamp' => time(), 'source' => 'manual', 'version' => time()]));
```

## Technical Details

### Pub/Sub

Pub/Sub provides near real-time cache invalidation across all containers. It requires a long-running subscriber process, which can be auto-started via configuration.

### Why both push and pull?

Pub/sub is the fast path (millisecond propagation) but is asynchronous and has no replay: a message published while a pod's subscriber is down is lost forever, and a request racing the delivery can rebuild shared assets from stale local state. The per-request epoch check is the durable correctness guarantee.

### Race Conditions?

**Scenario:** Admin clears cache (or toggles an extension / saves settings) during high traffic

Pub/sub alone is not safe here: delivery is asynchronous, so a request landing on another pod inside the delivery window used to rebuild the shared compiled assets from that pod's still-stale locale catalogue — poisoning them for everyone until the next invalidation. Under constant traffic there is virtually always such a request.

**Safe now because:**
1. The epoch is written to Redis before the message is published
2. Each pod checks the epoch synchronously before serving a request and clears its local caches first when behind — a rebuild can no longer start from stale local state
3. The shared compiled assets and their revision manifest are flushed **only by core, on the pod handling the admin action** — one actor, though each flush is a dozen or so sequential read-modify-writes of the manifest. This extension never touches them: an apply on every pod meant several more concurrent writers on `rev-manifest.json`, whose updates are non-atomic read-modify-writes — interleavings lose updates and leave a revision pointing at stale content, which browsers and CDNs then cache until the next admin action. This *reduces* the writer count (core's flush plus each pod's first rebuild, instead of that plus two applies per pod); the remaining race between core's flush and the first rebuilds is core's own and is not eliminated here
4. Applying an epoch also drops the shared settings cache, so a pre-change snapshot re-stored by a racing refill lives milliseconds, not the full TTL
5. Each pod applies independently and idempotently; concurrent workers on one pod are serialized by an atomic claim, and the applied epoch is recorded per pod (and per SAPI) so nothing is cleared twice
6. With `asset_revisions` in Redis (the default when pub/sub is enabled), every revision write is a single atomic hash field, so concurrent writers — core's own flush on the acting pod and every pod's first rebuild — can no longer lose each other's updates (see below)

**Residual window:** after core flushes the compiled assets on a toggle, the next request to render a page rebuilds them. If that request booted before the change was visible to it — just before the toggle, or while a racing refill's pre-change settings snapshot was still in Redis — it can bake the pre-change state into the shared asset, which then persists until the next admin action. The lazy rebuild is core's own 1.x behaviour (Flarum 2.x defers it to a freshly-booted request for exactly this reason); the pre-change settings snapshot is this extension's Redis settings layer, which is why the apply re-forgets it. Clicking *Clear Cache* once after a toggle rebuilds from a settled state. Making the manifest writes themselves atomic (a Redis-backed `VersionerInterface`, which core's `RevisionCompiler` accepts by injection) would remove the lost-update half of this; it is a candidate follow-up.

### Asset revisions in Redis

Core records which revision of each compiled asset is current in `rev-manifest.json`, next to the assets. Its `FileVersioner` updates that file with a **read-modify-write of the whole file**: read the JSON, change one key, write it all back. After an admin action several actors do that at once — core's flush on the acting pod is a dozen or so sequential writes (css, js and one locale css/js per locale, per frontend), and every pod's first render after the flush commits its own — so interleavings lose updates. Because `RevisionCompiler::getUrl()` only commits when a revision is *missing*, a lost update leaves a stale revision in place until the next admin action, and browsers and CDNs keep the stale URL. On assets stored on S3 each write is a network round trip, which makes the interleaving likely rather than exotic.

Symptoms: an extension's settings page rendering empty right after enabling it (`admin.js` kept an old revision), a disable not reflected on the forum (`forum.js` did), or mixed states (`admin-de.js` advanced while `admin.js` did not).

When pub/sub is enabled (or `'asset_revisions' => 'redis'` is set explicitly), this extension binds core's `VersionerInterface` to a Redis-backed versioner: the revisions live in one hash — `flarum:assets:revisions`, namespaced by the configured `prefix` like every other key on the cache connection — and every write is a single-field `HSET`/`HDEL`: atomic, no read-modify-write, nothing to lose. Core's `RevisionCompiler` accepts a versioner through the container, so **core's own flush uses it too**. Reads go to Redis on every call, like core's own versioner reads the manifest on every call (a handful of sub-millisecond `HGET`s per page render).

What it does not change: the lazy rebuild-by-whoever-comes-next is core's design and stays; a request that booted before a toggle can still rebuild an asset from pre-change sources (see the residual window above).

Operational notes:

- **Requires the cache service.** The hash lives on the `fof.cache` connection; with `->disable(['cache'])` the setting has no effect and core's file stays in use.
- **Split `connections` config:** in the "Completely separate the config array" form, `Configuration::for('cache')` uses the `connections.cache` block *instead of* the top level — so `asset_revisions` (like `pubsub` and `prefix`) must be placed **inside** `connections.cache`, or it is silently ignored.
- **Switching over** is like a cache clear: the hash starts empty, so every asset is rebuilt once on its next request. `rev-manifest.json` is no longer read or written from then on — which means it **freezes**. If you ever switch back to `'file'`, run `php flarum cache:clear` right away: otherwise the frozen manifest hands out old `?v=` revisions for files whose contents have since changed, and browsers and CDNs keep serving the old bytes until the next admin action.
- `php flarum cache:clear` (and the admin panel's *Clear Cache*) flush the cache connection's database (`FLUSHDB`) before core flushes the assets, which also empties the hash — the intended "rebuild everything" outcome of a cache clear. Under an `allkeys-*` eviction policy Redis may also evict the hash under memory pressure; the effect is the same as a cache clear (every asset rebuilt once).
- **Errors are not swallowed.** A Redis error while reading or writing a revision surfaces as an error page, exactly like a storage error in core's own `FileVersioner` — and like the cache, settings and session stores this extension already puts on Redis. That is deliberate: a read failure reported as "no revision" would make core recompile on every request and then render pages without any CSS or JS, and a write failure reported as success would leave a stale revision in place with nothing to trigger a retry.

## Future Improvements

Possible enhancements (not currently needed):

- [ ] Metrics/monitoring for cache propagation
- [ ] Admin UI indicator showing last sync time
- [ ] Subscriber heartbeat metrics
- [ ] Selective invalidation (only specific cache types)

## Credits

Developed to solve distributed cache coherency issues in horizontally scaled Flarum deployments running on Kubernetes/ECS.

**Architecture inspired by:**
- Laravel's `queue:restart` command (uses same polling pattern)
- Symfony's cache tagging system
- Redis as a shared coordination layer
