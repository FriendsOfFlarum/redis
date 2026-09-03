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

An apply is deliberately **non-destructive to anything a concurrent request may be using**. It forgets cache entries rather than deleting the files they reference:

- the file cache (`storage/cache`), including the serialized TextFormatter entry
- `storage/locale/*` — the compiled Symfony catalogues, plus their OPcache entries. These are the only files an apply deletes: their names are not content-derived, so nothing else would detect staleness
- the shared settings cache (`flarum:settings`) and the resolved settings repository instance

Deliberately **not** touched:

- `storage/formatter/*` — the generated renderer classes. Their names are content hashes, so a rebuilt formatter simply writes new files. Deleting them mid-traffic breaks requests that are unserializing the cached formatter (incomplete object → `\Error` → 500). Core's own `Formatter::flush()` behaves the same way; `cache:clear` sweeps the orphans
- `storage/views/*` — Blade recompiles by source mtime, and toggles or settings saves never change template sources
- the shared compiled frontend assets and `rev-manifest.json` — core flushes those itself, once, on the pod handling the admin action

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

The epoch record is written to `<flarum root>/cache-epoch-<hostname>-<sapi>` — the base path must be writable by the web user. If it is not, the backstop disables itself and logs a warning (it never loops). The hostname suffix keeps records per pod even when the install root is a shared volume; the SAPI suffix lets php-fpm perform its own apply (a CLI subscriber cannot reset php-fpm's OPcache).

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
3. The shared compiled assets and their revision manifest are flushed **only by core, once, on the pod handling the admin action**. This extension never touches them: an apply on every pod meant several concurrent writers on `rev-manifest.json`, whose updates are non-atomic read-modify-writes — interleavings lose updates and leave a revision pointing at stale content, which browsers and CDNs then cache until the next admin action
4. Applying an epoch also drops the shared settings cache, so a pre-change snapshot re-stored by a racing refill lives milliseconds, not the full TTL
5. Each pod applies independently and idempotently; concurrent workers on one pod are serialized by an atomic claim, and the applied epoch is recorded per pod (and per SAPI) so nothing is cleared twice

**Residual window:** after core flushes the compiled assets on a toggle, the next request to render a page rebuilds them. If that request booted just before the toggle, it can bake the pre-change state into the shared asset, which then persists until the next admin action. This is core's own lazy-rebuild behaviour on 1.x (Flarum 2.x defers the rebuild to a freshly-booted request for exactly this reason); clicking *Clear Cache* once after a toggle rebuilds from a settled state.

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
