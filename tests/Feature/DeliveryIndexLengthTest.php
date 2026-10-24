<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Tests\Feature;

use Asignua\FilamentMailLog\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite does not enforce MySQL's index key limit (3072 bytes on InnoDB, 4 bytes per utf8mb4 character), so an index
 * over long varchar columns passes here and fails at `migrate` on production. This sums the declared lengths of
 * every index of every table the plugin creates.
 */
class DeliveryIndexLengthTest extends TestCase
{
    private const int MAX_INDEX_BYTES = 3072;

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function tables(): iterable
    {
        yield 'log' => ['filament-mail-log.table'];
        yield 'events' => ['filament-mail-log.webhooks.events_table'];
        yield 'suppressions' => ['filament-mail-log.suppression.table'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tables')]
    public function test_every_index_fits_the_mysql_key_length_limit(string $configKey): void
    {
        $table = (string) config($configKey);
        $columns = collect(Schema::getColumns($table))->keyBy('name');
        $indexes = Schema::getIndexes($table);

        $this->assertNotEmpty($indexes);

        foreach ($indexes as $index) {
            $bytes = 0;

            foreach ($index['columns'] as $name) {
                $bytes += $this->bytesOf((string) ($columns[$name]['type'] ?? ''));
            }

            $this->assertLessThanOrEqual(self::MAX_INDEX_BYTES, $bytes, "Index [{$index['name']}] of [{$table}] is {$bytes} bytes.");
        }
    }

    public function test_the_unique_keys_that_make_the_webhook_idempotent_exist(): void
    {
        $unique = fn (string $table): array => collect(Schema::getIndexes($table))->filter(fn (array $index): bool => $index['unique'] && !$index['primary'])->pluck('columns')->all();

        $this->assertContains(['provider', 'provider_event_id'], $unique((string) config('filament-mail-log.webhooks.events_table')));
        $this->assertContains(['email'], $unique((string) config('filament-mail-log.suppression.table')));
    }

    /**
     * varchar(255) is 1020 bytes; an unsized varchar is MySQL's 255; other types are small.
     */
    private function bytesOf(string $type): int
    {
        if (preg_match('/^(?:var)?char\((\d+)\)/i', $type, $m) === 1) {
            return (int) $m[1] * 4;
        }

        return preg_match('/^(?:var)?char$/i', $type) === 1 ? 255 * 4 : 8;
    }
}
