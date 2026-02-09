# Redis sessions, cache & queues

This library allows using Redis as cache, session and for the queue. You can only 
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

This enables sessions, cache and queue to run on redis.

> **Multi-instance deployments:** This extension can handle distributed cache invalidation across multiple Flarum instances (pods/containers) via Redis Pub/Sub. See [DISTRIBUTED_CACHE.md](DISTRIBUTED_CACHE.md) for details.

> **Important:** See "Use different database for each service" below to split up the database for cache vs sessions, queue
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
    ]))->disable(['cache', 'queue'])
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
];
```

5. Enable cache invalidation pub/sub (auto-start subscriber after app boot):

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

#### Queue

Make sure to start your queue workers, see 
the [laravel documentation](https://laravel.com/docs/8.x/queues#running-the-queue-worker) for specifics. 
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

You can read up on the meaning of these options in the [Laravel Documentation](https://laravel.com/docs/8.x/queues#redis).

### Migrating from `blomstra/flarum-redis`

Simply update the namespace used in your `extend.php` file from `Blomstra\Redis...` to `FoF\Redis...`

### Updating

```sh
composer update fof/redis
```

### FAQ

#### Why are there still files in storage/cache?

Some code still relies on physical files being present. This includes:
- **Formatter cache** (`storage/formatter/*`) - TextFormatter configuration
- **Locale cache** (`storage/locale/*`) - Compiled Symfony translation catalogues
- **View cache** (`storage/views/*`) - Compiled Blade templates

These file caches can be synchronized across multiple instances using Redis Pub/Sub. See [DISTRIBUTED_CACHE.md](DISTRIBUTED_CACHE.md) for details.

#### Running multiple Flarum instances (horizontal scaling)?

When running Flarum across multiple pods/containers (Kubernetes, ECS, etc.), cache invalidation can be handled by this extension via Pub/Sub.

**How it works:**
- When cache is cleared on one instance, all other instances are notified via Pub/Sub
- File caches are synchronized immediately
- Requires the cache subscriber process (auto-start available)

**See [DISTRIBUTED_CACHE.md](DISTRIBUTED_CACHE.md)** for complete documentation on:
- Architecture and implementation details
- Performance characteristics
- Troubleshooting tips
- Configuration options

### Events

#### CacheConnectionReady

Dispatched after the application boot sequence completes (core `ApplicationBooted` event).

### Links

- [Packagist](https://packagist.org/packages/fof/redis)
- [GitHub](https://github.com/FriendsOfFlarum/redis)
