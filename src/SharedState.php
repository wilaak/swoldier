<?php

namespace Swoldier;

use Swoole\{Table, Atomic, Lock};

/**
 * Registry for storing Tables, Atomics and Locks for shared state across workers.
 */
class SharedState
{
    private static array $entries = [];

    /**
     * Store a shared memory entry
     * 
     * @param string $key Entry key
     * @param Table|Atomic|Lock $value Shared memory object
     */
    public static function set(string $key, Table|Atomic|Lock $value): void
    {
        if (isset(self::$entries[$key])) {
            throw new \RuntimeException("Entry with key '{$key}' already exists.");
        }
        self::$entries[$key] = $value;
    }

    /**
     * Retrieve a shared memory entry
     * 
     * @param string $key Entry key
     * @return Table|Atomic|Lock|null Shared memory object or null if not found
     */
    public static function get(string $key): Table|Atomic|Lock|null
    {
        return self::$entries[$key] ?? null;
    }
}
