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

3. All pods stay synchronized in near real time (message delivery is asynchronous — a request racing the delivery window can still rebuild from stale state for a moment)

### What Gets Invalidated

- `storage/formatter/*` - TextFormatter cache
- `storage/locale/*` - Symfony translation catalogues
- `storage/views/*` - Blade view cache
- In-memory Symfony translator catalogues

## Architecture

### Components

1. **Publisher** (Redis)
   - Channel: `flarum:cache:invalidate`
   - Message: JSON with `timestamp`, `source`, `version`

2. **Subscriber** (`cache:subscribe` command)
   - Subscribes to the channel
   - Clears local caches immediately

### Performance

Pub/Sub has near-zero per-request overhead and only incurs work on cache clears.

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

### Why Static Variables?

Pub/Sub avoids per-request checks entirely and only does work when a cache clear happens.

### Race Conditions?

**Scenario:** Admin clears cache during high traffic

**Safe because:**
1. Event fires AFTER core finishes clearing
2. Version signal written to Redis after local clear
3. Other pods receive the message and clear immediately
4. Each pod clears independently (no locks needed)
5. Worst case: Brief delay for message delivery

**Not a problem because:**
- Cache clears are infrequent (admin operations)
- 5-second delay is acceptable
- Eventually consistent is sufficient

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
