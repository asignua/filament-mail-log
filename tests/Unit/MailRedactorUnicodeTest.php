<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Tests\Unit;

use Asignua\FilamentMailLog\Support\MailRedactor;
use Asignua\FilamentMailLog\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class MailRedactorUnicodeTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}> input, a fragment that must NOT survive
     */
    public static function secrets(): array
    {
        return [
            'cyrillic label' => ["Ваш вхід\nПароль: secret123\nДякуємо", 'secret123'],
            'upper case cyrillic' => ['ПАРОЛЬ: secret123', 'secret123'],
            'mixed case' => ['Код = 482913', '482913'],
            'token uk' => ['Токен: abcDEF', 'abcDEF'],
            'pin uk' => ['Пін: 4821', '4821'],
            'nbsp entity' => ['Пароль&nbsp;: secret123', 'secret123'],
            'literal nbsp' => ["Пароль\u{00A0}: secret123", 'secret123'],
            'html wrapped' => ['<p><b>Пароль:</b> secret123</p>', 'secret123'],
            'after punctuation' => ['(Пароль: secret123)', 'secret123'],
            'de' => ['Passwort: geheim99', 'geheim99'],
            'pl' => ['Hasło: tajne123', 'tajne123'],
            'es' => ['Contraseña: clave123', 'clave123'],
            'es code' => ['CÓDIGO: 998877', '998877'],
            'cyrillic path-less query' => ['https://x.test/cb?TOKEN=SECRETVALUE&a=1', 'SECRETVALUE'],
        ];
    }

    #[DataProvider('secrets')]
    public function test_non_ascii_labels_are_redacted(string $input, string $secret): void
    {
        $result = MailRedactor::apply($input);

        $this->assertTrue($result->redacted);
        $this->assertStringNotContainsString($secret, (string) $result->text);
        $this->assertStringContainsString(MailRedactor::PLACEHOLDER, (string) $result->text);
    }

    public function test_label_is_kept_and_text_around_survives(): void
    {
        $this->assertSame(
            "Привіт\nПароль: [REDACTED]\nДо зустрічі",
            MailRedactor::clean("Привіт\nПароль: secret123\nДо зустрічі"),
        );
    }

    public function test_label_inside_a_longer_word_is_not_a_label(): void
    {
        $this->assertSame('Прокод: ok', MailRedactor::clean('Прокод: ok'));
        $this->assertSame('mypassword: ok', MailRedactor::clean('mypassword: ok'));
    }

    public function test_ascii_behaviour_is_unchanged(): void
    {
        $this->assertSame('Password: [REDACTED]', MailRedactor::clean('Password: hunter2'));
        $this->assertSame('api_key = [REDACTED]', MailRedactor::clean('api_key = sk_live_1'));
        $this->assertSame('plain text', MailRedactor::clean('plain text'));
    }

    public function test_invalid_utf8_is_scrubbed_not_failed(): void
    {
        $result = MailRedactor::apply("Пароль: secret123\n".substr('Привіт', 0, 3));

        $this->assertNotSame(MailRedactor::FAILED, $result->text);
        $this->assertStringNotContainsString('secret123', (string) $result->text);
    }

    public function test_long_hostile_input_does_not_blow_up(): void
    {
        $text = str_repeat('Пароль ', 20_000).str_repeat('<', 5_000);
        $result = MailRedactor::apply($text);

        $this->assertNotNull($result->text);
    }
}
