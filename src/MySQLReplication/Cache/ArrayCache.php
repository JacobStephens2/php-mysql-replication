<?php

declare(strict_types=1);

namespace MySQLReplication\Cache;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

class ArrayCache implements CacheInterface
{
    private array $tableMapCache = [];

    public function __construct(
        private readonly int $tableCacheSize = 0
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->has($key) ? $this->tableMapCache[$key] : $default;
    }

    public function has($key): bool
    {
        return isset($this->tableMapCache[$key]);
    }

    public function clear(): bool
    {
        $this->tableMapCache = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $data = [];
        foreach ($keys as $key) {
            if ($this->has($key)) {
                $data[$key] = $this->tableMapCache[$key];
            }
        }

        return $data !== [] ? $data : (array)$default;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }

        return true;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        // automatically clear table cache to save memory
        if (count($this->tableMapCache) > $this->tableCacheSize) {
            $this->tableMapCache = array_slice($this->tableMapCache, (int)($this->tableCacheSize / 2), null, true);
        }

        $this->tableMapCache[$key] = $value;

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->tableMapCache[$key]);

        return true;
    }
}
