<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Tests\Feature;

use Asignua\FilamentMailLog\Tests\TestCase;
use Illuminate\Support\Arr;

class TranslationsTest extends TestCase
{
    private const array LOCALES = ['de', 'en', 'es', 'fr', 'it', 'nl', 'pl', 'pt_BR', 'tr', 'uk'];

    public function test_every_locale_has_the_same_keys_as_english(): void
    {
        $english = $this->keys('en');

        $this->assertNotEmpty($english);

        foreach (self::LOCALES as $locale) {
            $this->assertSame($english, $this->keys($locale), "Locale [{$locale}] is out of step with [en].");
        }
    }

    public function test_every_locale_keeps_the_placeholders_of_english(): void
    {
        $english = $this->strings('en');

        foreach (self::LOCALES as $locale) {
            $strings = $this->strings($locale);

            foreach ($english as $key => $value) {
                $this->assertSame(
                    $this->placeholders($value),
                    $this->placeholders($strings[$key]),
                    "Locale [{$locale}] changes the placeholders of [{$key}].",
                );
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function strings(string $locale): array
    {
        $path = __DIR__.'/../../resources/lang/'.$locale.'/filament-mail-log.php';

        $this->assertFileExists($path);

        return Arr::dot(require $path);
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $value): array
    {
        preg_match_all('/:[A-Za-z_]+/', $value, $matches);

        $found = $matches[0];
        sort($found);

        return $found;
    }

    /**
     * @return list<string>
     */
    private function keys(string $locale): array
    {
        $path = __DIR__.'/../../resources/lang/'.$locale.'/filament-mail-log.php';

        $this->assertFileExists($path);

        $keys = array_keys(Arr::dot(require $path));
        sort($keys);

        return $keys;
    }
}
