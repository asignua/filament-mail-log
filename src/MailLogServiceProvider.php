<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class MailLogServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-mail-log';

    public function configurePackage(Package $package): void
    {
        // Translations live in resources/lang/<locale>/filament-mail-log.php and are read as
        // `__('filament-mail-log::filament-mail-log.<key>')`. Publish tag: `filament-mail-log-translations`.
        $package->name(static::$name)
            ->hasTranslations()
            ->hasViews();

        // Add a config file only when the plugin really has options: create config/filament-mail-log.php and
        // chain `->hasConfigFile()` here (publish tag `filament-mail-log-config`). Prefer fluent setters on the Plugin.
    }
}
