<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Support;

use Asignua\FilamentMailLog\Models\MailLog;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Which of the delivery-tracking tables the host has migrated. A host that updated the package but has not
 * published and run the new migrations must keep logging and keep its panel working: every tracking feature
 * asks here first, and a missing table hides the feature instead of throwing.
 */
final class TrackingSchema
{
    /** @var array<string, bool> */
    private static array $cache = [];

    public static function hasStatusColumn(): bool
    {
        $log = new MailLog;

        return self::column($log->getConnectionName(), $log->getTable(), 'delivery_status');
    }

    public static function hasEventsTable(): bool
    {
        return self::table((string) config('filament-mail-log.webhooks.events_table', 'mail_log_events'));
    }

    public static function hasSuppressionsTable(): bool
    {
        return self::table((string) config('filament-mail-log.suppression.table', 'mail_log_suppressions'));
    }

    /**
     * Whether any provider is switched on (decides whether outgoing mail is stamped with the log id).
     */
    public static function anyProviderEnabled(): bool
    {
        $providers = config('filament-mail-log.webhooks.providers', []);

        foreach (is_array($providers) ? $providers : [] as $provider) {
            if (is_array($provider) && ($provider['enabled'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    public static function forget(): void
    {
        self::$cache = [];
    }

    private static function column(?string $connection, string $table, string $column): bool
    {
        $key = ($connection ?? '').'.'.$table.'.'.$column;

        if (!array_key_exists($key, self::$cache)) {
            try {
                self::$cache[$key] = Schema::connection($connection)->hasColumn($table, $column);
            } catch (Throwable) {
                return false;
            }
        }

        return self::$cache[$key];
    }

    private static function table(string $table): bool
    {
        $connection = (new MailLog)->getConnectionName();
        $key = ($connection ?? '').'.'.$table;

        if (!array_key_exists($key, self::$cache)) {
            try {
                self::$cache[$key] = Schema::connection($connection)->hasTable($table);
            } catch (Throwable) {
                return false;
            }
        }

        return self::$cache[$key];
    }
}
