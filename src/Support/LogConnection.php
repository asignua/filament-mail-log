<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Support;

use Illuminate\Support\Facades\DB;

/**
 * The connection the log is written on.
 *
 * An explicit `connection` setting wins. Otherwise the log gets its own connection that clones the default
 * one: a mail that was sent inside a `DB::transaction()` which later rolls back must keep its audit row, and
 * on the caller's connection the row would be rolled back with it. An in-memory SQLite database can not be
 * cloned (a second connection would see another empty database), so it stays on the default connection.
 */
final class LogConnection
{
    public const string NAME = 'filament-mail-log';

    public static function name(): ?string
    {
        $configured = config('filament-mail-log.connection');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if (!(bool) config('filament-mail-log.isolate_connection', true)) {
            return null;
        }

        $default = config('database.default');
        $settings = is_string($default) ? config('database.connections.'.$default) : null;

        if (!is_array($settings) || self::isInMemory($settings)) {
            return null;
        }

        if (config('database.connections.'.self::NAME) !== $settings) {
            config()->set('database.connections.'.self::NAME, $settings);
            DB::purge(self::NAME);
        }

        return self::NAME;
    }

    /**
     * @param array<array-key, mixed> $settings
     */
    private static function isInMemory(array $settings): bool
    {
        if (($settings['driver'] ?? null) !== 'sqlite') {
            return false;
        }

        $database = $settings['database'] ?? '';

        return !is_string($database) || $database === '' || $database === ':memory:' || str_contains($database, 'mode=memory');
    }
}
