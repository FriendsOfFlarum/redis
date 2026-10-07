<?php

namespace FoF\Redis\Traits;

use Exception;

trait RetrievesRedisInfo
{
    /**
     * Retrieves Redis info with error handling.
     *
     * phpredis returns a flat array from `info()`, while Predis returns a nested
     * array keyed by section name (e.g. `['Memory' => [...]]`). Consumers use
     * dot-notation lookups like `Arr::get($info, 'Memory.maxmemory_policy')`
     * which require the nested format, so normalise the flat phpredis output.
     *
     * @return array
     */
    protected function getInfo(): array
    {
        try {
            $name = $this->resolveConnectionName();

            if ($name === null) {
                return [
                    'error' => 'No Redis connection is registered.',
                ];
            }

            $connection = $this->redis->connection($name);
            $info = $connection->info();

            // If already nested (Predis-style), return as-is.
            if (isset($info['Memory']) && is_array($info['Memory'])) {
                return $info;
            }

            // phpredis returns a flat array — rebuild into section-keyed structure
            // by fetching each section individually.
            $sections = ['server', 'clients', 'memory', 'stats', 'replication', 'cpu', 'keyspace'];
            $nested = [];

            foreach ($sections as $section) {
                $sectionData = $connection->info($section);
                if (is_array($sectionData) && !empty($sectionData)) {
                    $nested[ucfirst($section)] = $sectionData;
                }
            }

            return $nested;
        } catch (Exception $e) {
            return [
                'error' => 'Redis connection failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Just the server section: what the store is and which version. One round
     * trip, where getInfo() takes up to eight.
     *
     * @return array The section's fields, or `['error' => ...]`.
     */
    protected function getServerInfo(): array
    {
        try {
            $name = $this->resolveConnectionName();

            if ($name === null) {
                return [
                    'error' => 'No Redis connection is registered.',
                ];
            }

            $info = $this->redis->connection($name)->info('server');

            // Predis nests the section under its name; phpredis returns it flat.
            return is_array($info['Server'] ?? null) ? $info['Server'] : $info;
        } catch (Exception $e) {
            return [
                'error' => 'Redis connection failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Pick a Redis connection that is actually registered.
     *
     * Connections are named per service ('fof.cache', 'fof.sessions', …) and
     * only 'queue' registers a 'default'. A bare connection() call resolves to
     * 'default' and therefore fails on, say, a cache-only install — so probe
     * the registered names instead.
     */
    protected function resolveConnectionName(): ?string
    {
        foreach (['fof.cache', 'fof.sessions', 'fof.settings', 'default'] as $name) {
            if ($this->redis->getConnectionConfig($name) !== null) {
                return $name;
            }
        }

        return null;
    }
}
