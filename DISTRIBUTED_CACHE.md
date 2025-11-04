# Distributed Cache Invalidation

## Overview

When running multiple Flarum instances (pods/containers) with Redis cache, cache clearing on one instance needs to be propagated to all other instances. This feature automatically handles distributed cache invalidation.

## How It Works

### The Problem

When you clear cache via admin panel or `php flarum cache:clear`:
1. **Pod A** clears its Redis cache (shared) ✅
2. **Pod A** clears its file caches (local) ✅
3. **Pods B, C, D** still have stale file caches ❌

This causes missing translations, stale assets, and other cache-related issues.

### The Solution

**Automatic propagation using Redis as a signal bus:**

1. When cache is cleared on Pod A:
   - Clears local Redis + file caches
   - Sets `flarum:cache:version` = `1730793600` in Redis

2. On next request to Pod B (within 5 seconds):
   - Middleware checks `flarum:cache:version` from Redis
   - Compares with local version (stored in APCu)
   - If different: invalidates local file caches
   - Updates local version

3. All pods eventually consistent within 5 seconds

### What Gets Invalidated

- `storage/formatter/*` - TextFormatter cache
- `storage/locale/*` - Symfony translation catalogues
- `storage/views/*` - Blade view cache
- In-memory Symfony translator catalogues

## Architecture

### Components

1. **Version Signal** (Redis)
   - Key: `flarum:cache:version`
   - Value: Unix timestamp of last cache clear
   - Shared across all instances

2. **Local Version Tracker** (APCu)
   - Key: `flarum:local:cache:version`
   - Value: Last seen global version
   - Per PHP-FPM worker

3. **Middleware** (`DistributedCacheInvalidation`)
   - Checks version every 5 seconds (configurable)
   - Invalidates local caches if version changed
   - Registered on forum, admin, and API pipelines

### Performance

**With default 5-second throttle:**
- 1 Redis GET per PHP-FPM worker per 5 seconds
- ~0.5-1ms per check
- APCu caches the result in-memory
- Negligible overhead: ~0.002ms per request average

**Scalability example:**
- 1000 requests/second = ~200 PHP-FPM workers
- 200 workers / 5 seconds = 40 Redis GETs/second
- Total overhead: ~20-40ms/second across entire fleet

## Configuration

### Optional Settings

Add to your `config.php`:

```php
return [
    // ... other config

    'cache' => [
        // How often to check for cache invalidation (seconds)
        // Default: 5
        'distributed_invalidation_interval' => 5,
    ],
];
```

### Tuning the Interval

- **Lower (1-2s)**: Faster propagation, more Redis load
- **Higher (10-30s)**: Less Redis load, slower propagation
- **Recommendation**: 5s is optimal for most deployments

## Requirements

### Required
- fof/redis installed and configured
- Redis connection available
- Multiple Flarum instances

### No Additional Requirements
- Uses PHP static variables for per-worker caching
- No special PHP extensions needed

## Backward Compatibility

✅ **Single instance**: Works with tiny overhead
✅ **No special extensions**: Uses PHP static variables
✅ **Redis unavailable**: Fails gracefully
✅ **Existing installs**: No migration needed

## Deployment

### No Additional Setup Required!

The feature is **automatic** when you install fof/redis:

1. Install/upgrade fof/redis
2. Clear cache: `php flarum cache:clear`
3. ✅ Done! All instances will stay synchronized

### Testing

**Verify it's working:**

```bash
# On Pod A - clear cache
php flarum cache:clear

# Check Redis has version key
redis-cli -n 1
> GET flarum:cache:version
"1730793600"

# On Pod B - make a request (triggers middleware)
curl https://your-forum.com

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


**Check middleware is registered:**
```bash
# Should see DistributedCacheInvalidation in middleware stack
php flarum list
```

### High Redis load?

Increase the check interval:
```php
// config.php
'cache' => [
    'distributed_invalidation_interval' => 10, // Check every 10s instead of 5s
],
```

### Manual invalidation for testing

```php
// Force all instances to invalidate on next request
php flarum tinker
>>> resolve(\Illuminate\Contracts\Redis\Factory::class)->connection('fof.cache')->set('flarum:cache:version', time());
```

## Technical Details

### Why Not Pub/Sub?

**Redis Pub/Sub** was considered but rejected because:
- ❌ Requires long-running subscriber daemon process
- ❌ Additional process management (supervisor, systemd)
- ❌ Restart on deploy/crash recovery
- ❌ Operational complexity

**Polling approach** is simpler:
- ✅ No daemons needed
- ✅ Works automatically
- ✅ Negligible overhead with throttling
- ✅ Self-healing (checks on every request)

### Why Static Variables?

**Static variables** in PHP persist across requests within the same PHP-FPM worker process

- Stores "last known version" in each PHP-FPM worker's memory
- Combined with throttling (5-second check interval), avoids checking Redis on every request
- No external dependencies or extensions needed

**Flow with throttling + static variables:**
```
Request 1 @ T+0s → Check Redis (1ms) → Store in static var → Continue
Request 2 @ T+1s → Check throttle → Skip (last check was 1s ago) → Continue
Request 3 @ T+2s → Check throttle → Skip (last check was 2s ago) → Continue
Request 4 @ T+5s → Check throttle → Check Redis (1ms) → Update static var → Continue
```

**Benefits:**
- Only 1 Redis check per worker per 5 seconds
- Static variable lookups are instant (in-memory, same process)
- Works everywhere - no extensions needed

### Race Conditions?

**Scenario:** Admin clears cache during high traffic

**Safe because:**
1. Event fires AFTER core finishes clearing
2. Version signal written to Redis after local clear
3. Other pods check version periodically
4. Each pod clears independently (no locks needed)
5. Worst case: Request sees stale cache for up to 5 seconds

**Not a problem because:**
- Cache clears are infrequent (admin operations)
- 5-second delay is acceptable
- Eventually consistent is sufficient

## Future Improvements

Possible enhancements (not currently needed):

- [ ] Metrics/monitoring for cache propagation
- [ ] Admin UI indicator showing last sync time
- [ ] Optional immediate invalidation via Realtime WebSocket
- [ ] Selective invalidation (only specific cache types)

## Credits

Developed to solve distributed cache coherency issues in horizontally scaled Flarum deployments running on Kubernetes/ECS.

**Architecture inspired by:**
- Laravel's `queue:restart` command (uses same polling pattern)
- Symfony's cache tagging system
- Redis as a shared coordination layer
