<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Support;

/**
 * Labels of mailable / notification classes: the configured registry, then the registry entry of a parent
 * class (a host class that only extends a library's mail), then the short class name.
 */
final class MailTypes
{
    public static function label(?string $class): string
    {
        if ($class === null || $class === '') {
            return '—';
        }

        $registry = config('filament-mail-log.types');
        $registry = is_array($registry) ? $registry : [];

        if (isset($registry[$class]) && is_string($registry[$class])) {
            return $registry[$class];
        }

        // class_exists() first: the stored class may have been renamed or deleted since.
        if (class_exists($class)) {
            foreach (class_parents($class) ?: [] as $parent) {
                if (isset($registry[$parent]) && is_string($registry[$parent])) {
                    return $registry[$parent];
                }
            }
        }

        return class_basename($class);
    }
}
