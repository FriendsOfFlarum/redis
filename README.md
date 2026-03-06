# Redis sessions, cache, queues & settings

This library allows using Redis as cache, session, settings storage, and for the queue. You can only
enable these services by using a local extender (the `extend.php` in
the root of your Flarum installation). See the "Set up" section below.

> This is an advanced utility for webmasters!

### Installation
Install manually with composer:

```sh
composer require fof/redis:"*"
```

### Set up

In your `extend.php`:

```php
return [
    new FoF\Redis\Extend\Redis([
        'host' => '127.0.0.1',
        'password' => null,
        'port' => 6379,
        'database' => 1
    ])
];
```

This enables sessions, cache, settings, and queue to run on redis.

#### phpredis vs Predis

If the PHP `redis` extension (`ext-redis`) is installed, it will be used automatically. Otherwise Predis is used as a fallback. No configuration change is required.

**Persistent connections (recommended for phpredis)**

With phpredis, connections can be reused across requests within the same PHP-FPM worker process. Add `persistent` and `persistent_id` to your config to enable this:

```php
return [
    new FoF\Redis\Extend\Redis([
        'host'          => '127.0.0.1',
        'password'      => null,
        'port'          => 6379,
        'database'      => 1,
        'persistent'    => true,
        'persistent_id' => 'flarum',  // groups connections into a named pool per worker
    ])
];
```

This avoids opening a new TCP connection on every request, which is a significant throughput improvement at scale. `persistent_id` is a string used to key the connection slot — use the same value across all workers on the same host.

**Unix socket**

```php
return [
    new FoF\Redis\Extend\Redis([
        'host'          => '/var/run/redis/redis.sock',
        'port'          => 0,
        'database'      => 1,
        'persistent'    => true,
        'persistent_id' => 'flarum',
    ])
];
```

**Other phpredis options**

| Key | Example | Notes |
|---|---|---|
| `timeout` | `2.0` | Connect timeout in seconds |
| `read_timeout` | `2.0` | Per-command timeout |
| `retry_interval` | `100` | ms between reconnect attempts |
| `prefix` | `'flarum_'` | Key prefix |
| `compression` | `Redis::COMPRESSION_LZ4` | phpredis ≥ 5.3, requires lz4/zstd compiled into ext-redis |

> **Multi-instance deployments:** This extension can handle distributed cache invalidation across multiple Flarum instances (pods/containers) via Redis Pub/Sub. See [DISTRIBUTED_CACHE.md](DISTRIBUTED_CACHE.md) for details.

#### Settings Cache

This extension includes a **settings cache** that significantly improves performance by caching all Flarum settings in Redis. This eliminates hundreds to thousands of database queries per page load.

**How it works:**
- All settings are cached in Redis with a dedicated connection (database 4 by default)
- Settings are loaded from cache on first access, then served from memory
- Cache is invalidated on any settings write, ensuring consistency across multiple instances
- Perfect for multi-container/multi-pod deployments where settings changes must propagate immediately

**Configuration:**

The settings cache uses database 4 by default. To customize:

```php
return [
    (new FoF\Redis\Extend\Redis([
        'host' => '127.0.0.1',
        'password' => null,
        'port' => 6379,
        'database' => 1,
    ]))
    ->useDatabaseWith('cache', 1)
    ->useDatabaseWith('queue', 2)
    ->useDatabaseWith('session', 3)
    ->useDatabaseWith('settings', 4)  // Settings cache database
];
```

To use a completely separate Redis instance for settings:

```php
return [
    (new FoF\Redis\Extend\Redis([
        'connections' => [
            'cache' => [
              'host' => 'cache.yoursite.com',
              'database' => 1,
            ],
            'settings' => [
              'host' => 'settings.yoursite.com',
              'database' => 4,
            ],
            // ... other connections
        ],
    ]))
];
```

**Performance impact:**
- Reduces settings-related database queries by 97-99%
- Typical Flarum page load: 1,500+ settings queries → ~10 queries
- Cache invalidation ensures consistency in multi-instance environments

> See "Use different database for each service" below to split up the database for cache vs sessions, queue
> because a cache clear action will clear sessions and queue jobs as well if they share the same database.

#### Advanced configuration

1. Disable specific services:

```php
return [
    (new FoF\Redis\Extend\Redis([
        'host' => '127.0.0.1',
        'password' => null,
        'port' => 6379,
        'database' => 1,
    ]))->disable(['cache', 'queue', 'settings'])
];
```

2. Use different database for each service:

```php
return [
    (new FoF\Redis\Extend\Redis([
        'host' => '127.0.0.1',
        'password' => null,
        'port' => 6379,
        'database' => 1,
    ]))
    ->useDatabaseWith('cache', 1)
    ->useDatabaseWith('queue', 2)
    ->useDatabaseWith('session', 3)
    ->useDatabaseWith('settings', 4)
];
```

3. Completely separate the config array:

```php
return [
    (new FoF\Redis\Extend\Redis([
        'connections' => [
            'cache' => [
              'host' => 'cache.int.yoursite.com',
              'password' => 'foo-bar',
              'port' => 6379,
              'database' => 1,
            ],
            'queue' => [
              'host' => 'queue.int.yoursite.com',
              'password' => 'foo-bar',
              'port' => 6379,
              'database' => 1,
            ],
            'session' => [
              'host' => 'session.int.yoursite.com',
              'password' => 'foo-bar',
              'port' => 6379,
              'database' => 1,
            ],
            'settings' => [
              'host' => 'settings.int.yoursite.com',
              'password' => 'foo-bar',
              'port' => 6379,
              'database' => 4,
            ],
        ],
    ]))
];
```

4. Use a cluster:

```php
return [
    (new FoF\Redis\Extend\Redis([
        'host' => '127.0.0.1',
        'password' => null,
        'port' => 6379,
        'database' => 1,
        'options' => [
          'replication' => 'sentinel',
          'service' => 'mymaster:26379',
        ]
    ]))
    ->useDatabaseWith('cache', 1)
    ->useDatabaseWith('queue', 2)
    ->useDatabaseWith('session', 3)
    ->useDatabaseWith('settings', 4)
];
```

#### Queue

Make sure to start your queue workers, see 
the [laravel documentation](https://laravel.com/docs/11.x/queues#running-the-queue-worker) for specifics. 
To test the worker can start use `php flarum queue:work`.

##### Queue options

The queue allows for several options to be added, retry_after, block_for and after_commit. You can set these
by adding a `queue` array in the configuration:

```php
return [
    (new FoF\Redis\Extend\Redis([
        'host' => '127.0.0.1',
        'password' => null,
        'port' => 6379,
        'database' => 1,
        'queue' => [
            'retry_after' => 120, // seconds
            'block_for' => 5, // seconds
            'after_commit' => true 
        ]       
    ]))
    ->useDatabaseWith('cache', 1)
    ->useDatabaseWith('queue', 2)
    ->useDatabaseWith('session', 3)
];
```

You can read up on the meaning of these options in the [Laravel Documentation](https://laravel.com/docs/12.x/queues#redis).

### Migrating from `blomstra/flarum-redis`

Simply update the namespace used in your `extend.php` file from `Blomstra\Redis...` to `FoF\Redis...`

### Updating

```sh
composer update fof/redis
```

### FAQ

*Why are there still files in storage/cache?*
Some code still relies on physical files being present. This includes the formatter cache and the view caches.

### Links

- [Packagist](https://packagist.org/packages/fof/redis)
- [GitHub](https://github.com/FriendsOfFlarum/redis)
