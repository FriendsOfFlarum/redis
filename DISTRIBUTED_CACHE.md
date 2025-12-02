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

**Push-based invalidation with Redis Pub/Sub and per-pod listeners:**

1. When cache is cleared on Pod A:
   - Core clears local Redis + file caches
   - Sets `flarum:cache:version = 1730793600` in Redis
   - Publishes `{ "timestamp": 1730793600 }` to the `flarum.cache.cleared` channel

2. Every other pod runs `php flarum cache:listen-distributed-invalidation <pod-id>` as a sidecar/daemon:
   - Subscribes to `flarum.cache.cleared`
   - Executes `php flarum cache:clear` locally the moment a push event is received
   - Stores `flarum:cache:version:last_seen:<pod-id>` after each clear so dashboards/alerts can track drift

3. All pods receive the event instantly (sub-millisecond in Redis) and clear their file caches within the same second.

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
   - Updated every time `cache:clear` runs anywhere

2. **Last-Seen Tracker** (Redis)
   - Key: `flarum:cache:version:last_seen:<pod-id>`
   - Records the newest version each pod has applied (purely informational now)
   - Useful for monitoring dashboards and manual drift checks

3. **Listener Command** (`cache:listen-distributed-invalidation`)
   - Long-lived process per pod/container
   - Subscribes to `flarum.cache.cleared`
   - Runs the standard cache clear routine on push events
   - Handles reconnection, signal-driven shutdown, and exponential-ish backoff

4. **ClearingCache Hook**
   - Core event that this extension listens to
   - Writes the new version key and emits the Pub/Sub message in one transaction

### Performance

- Pub/Sub delivery inside Redis is typically <1ms
- Each listener idles on `SUBSCRIBE` with negligible CPU usage
- Cache clearing cost is identical to a manual `php flarum cache:clear`
- No startup reconciliation: brand-new pods reuse their fresh cache and only react to live events

## Configuration

### Required Runtime Process

Run one listener per pod/container (or per PHP-FPM host):

```bash
php flarum cache:listen-distributed-invalidation <pod-id>
```

- **`pod-id`** should be a stable identifier (e.g., Kubernetes pod name, ECS task ID). Defaults to `gethostname()` if omitted.
- The command never exits unless a signal (SIGTERM/SIGINT) is received.

### Environment Considerations

- Ensure the listener has access to the same Redis instance configured for `fof/redis`
- Allow outbound TCP to Redis Pub/Sub ports (default 6379)
- Provide sufficient permissions to run `php flarum cache:clear` inside the container/VM

## Requirements

### Required
- fof/redis installed and configured
- Redis connection available (data + Pub/Sub)
- Ability to run a persistent CLI process alongside each Flarum pod

### Nice to Have
- Supervisor (`systemd`, `supervisord`, s6, Kubernetes sidecar, etc.) to keep the listener alive
- Log aggregation so listener output (success/failure) is searchable

## Backward Compatibility

✅ **Single instance:** listener can run locally or be skipped (no distributed benefit needed)
✅ **Graceful degradation:** if listener is down, caches remain valid locally; restart listener to rejoin the stream
✅ **Existing installs:** no schema or config changes—just add the long-running command to each pod spec

## Deployment

### Default Flow

1. Install/upgrade fof/redis
2. Clear cache once: `php flarum cache:clear`
3. Launch the listener alongside every pod:
   ```bash
   php flarum cache:listen-distributed-invalidation forum-app-1
   ```
4. Repeat step 3 with distinct IDs for `forum-app-2`, `forum-app-3`, etc.

### Keeping the Listener Running

- **Kubernetes:** add a sidecar container running the command; share the same volume + env as the main container
- **VM/Bare metal:** create a `systemd` service or `supervisord` program entry; set `Restart=always`
- **Docker Compose:** add a lightweight service referencing the same image and command entrypoint

## Testing

**Verify push invalidation end-to-end:**

```bash
# On Pod B (or locally), start the listener with a recognizable ID
php flarum cache:listen-distributed-invalidation forum-app-2

# In another terminal / Pod A, trigger a clear
php flarum cache:clear

# Listener output should immediately log "Cache cleared successfully via listener."
```

**Verify restart behavior:**

```bash
# Stop the listener temporarily
CTRL+C

# Trigger cache clear elsewhere
php flarum cache:clear

# Restart the listener
php flarum cache:listen-distributed-invalidation forum-app-2

# It immediately resubscribes; since no reconciliation runs, ensure listener stays online for future clears
```

## Troubleshooting

### Listener not reacting
- Confirm the process is running (`ps aux | grep cache:listen-distributed` or supervisor UI)
- Use `redis-cli PUBSUB CHANNELS` to ensure `flarum.cache.cleared` is active
- Check logs for reconnection loops; increase Redis connection timeout if needed
- Verify each pod uses a unique `pod-id` so last-seen keys are not overwritten

### Cache still stale on one pod
- Ensure the listener has permission to execute `php flarum cache:clear`
- Inspect `flarum:cache:version:last_seen:<pod-id>`; if it lags behind `flarum:cache:version`, restart the listener
- Look for filesystem permission errors inside the listener logs when deleting `storage/*`

### Redis unavailable
- Listener will back off for 5 seconds and retry continuously
- Before resubscribing it reconciles `flarum:cache:version` with the pod’s last-seen marker so missed clears run once
- During outages, local caches remain untouched; once Redis returns, the listener resumes and handles the next live event automatically

## Technical Details

### Why a dedicated listener?

Polling middleware added measurable latency in high-throughput deployments and still left a multi-second window for stale cache. A dedicated listener:
- ✅ Delivers near-instant propagation via Redis Pub/Sub
- ✅ Keeps hot paths (web requests) free of Redis round trips
- ✅ Centralizes cache-clear logic inside the CLI process where filesystem work already happens

### Version Keys as Observability

With startup reconciliation removed, `flarum:cache:version:last_seen:<pod-id>` now serves monitoring purposes rather than control flow. Operators can compare `flarum:cache:version` and each pod's last-seen value to confirm listeners observe events in real time.

### Signal Handling & Shutdown

- Uses `pcntl_signal()` (when available) to intercept `SIGTERM`/`SIGINT`
- On shutdown request, disconnects from Redis gracefully to exit the `SUBSCRIBE` loop
- Process managers can rely on normal exit semantics for rolling deploys

## Future Improvements

- [ ] Metrics endpoint exposing last-seen version per pod
- [ ] Health check that ensures listener last cleared within N seconds (based on last-seen)
- [ ] Admin dashboard surface for listener status
- [ ] Smarter batching to skip redundant cache clears during rapid-fire deployments
