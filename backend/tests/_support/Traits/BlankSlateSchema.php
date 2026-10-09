<?php

declare(strict_types=1);

namespace Tests\Support\Traits;

/**
 * Order-independence helper for tests that build their schema inline (CREATE
 * TABLE IF NOT EXISTS) instead of via a schema trait. The :memory: SQLite
 * connection is shared across every test class, so a table (or rows) left by a
 * class using a different schema would otherwise leak in and make the suite pass
 * only in a lucky order. Call dropAllTables() at the very start of setUp().
 */
trait BlankSlateSchema
{
    protected function dropAllTables(): void
    {
        $db = db_connect();
        foreach ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->getResultArray() as $row) {
            $db->query('DROP TABLE IF EXISTS "' . $row['name'] . '"');
        }
    }
}
