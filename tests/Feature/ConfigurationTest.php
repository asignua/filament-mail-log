<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Tests\Feature;

use Asignua\FilamentMailLog\Models\MailLog;
use Asignua\FilamentMailLog\Tests\Fixtures\WelcomeMail;
use Asignua\FilamentMailLog\Tests\TestCase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class ConfigurationTest extends TestCase
{
    public function test_the_table_name_is_configurable(): void
    {
        config()->set('filament-mail-log.table', 'my_mail_log');

        $this->assertSame('my_mail_log', (new MailLog)->getTable());

        (include __DIR__.'/../../database/migrations/create_mail_logs_table.php.stub')->up();
        $this->assertTrue(Schema::hasTable('my_mail_log'));

        Mail::to('ann@example.test')->send(new WelcomeMail);

        $this->assertSame(1, MailLog::query()->count());
        $this->assertSame(0, $this->app['db']->table('mail_logs')->count());
    }

    public function test_the_connection_is_configurable(): void
    {
        $this->assertNull((new MailLog)->getConnectionName());

        config()->set('filament-mail-log.connection', 'logs');

        $this->assertSame('logs', (new MailLog)->getConnectionName());
    }

    public function test_the_migration_is_publishable(): void
    {
        $paths = \Illuminate\Support\ServiceProvider::pathsToPublish(\Asignua\FilamentMailLog\MailLogServiceProvider::class, 'filament-mail-log-migrations');

        $this->assertNotEmpty($paths);
    }

    public function test_the_config_and_translations_are_publishable(): void
    {
        $this->assertNotEmpty(\Illuminate\Support\ServiceProvider::pathsToPublish(\Asignua\FilamentMailLog\MailLogServiceProvider::class, 'filament-mail-log-config'));
        $this->assertNotEmpty(\Illuminate\Support\ServiceProvider::pathsToPublish(\Asignua\FilamentMailLog\MailLogServiceProvider::class, 'filament-mail-log-translations'));
    }
}
