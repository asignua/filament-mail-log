<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Tests\Unit;

use Asignua\FilamentMailLog\Support\MailRedactor;
use Asignua\FilamentMailLog\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class MailRedactorTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}> input, a fragment that must NOT survive
     */
    public static function secrets(): array
    {
        return [
            'signature in a plain url' => ['https://x.test/verify/1?expires=1&signature=abc123def', 'abc123def'],
            'signature in html with &amp;' => ['<a href="https://x.test/v?expires=1&amp;signature=abc123def">go</a>', 'abc123def'],
            'first parameter' => ['https://x.test/cb?token=SECRETVALUE&a=1', 'SECRETVALUE'],
            'upper case parameter name' => ['https://x.test/cb?SIGNATURE=SECRETVALUE', 'SECRETVALUE'],
            'reset password path' => ['https://x.test/reset-password/7f3a9c0d1e?email=a%40b.c', '7f3a9c0d1e'],
            'nested reset path' => ['https://x.test/en/password/reset/TOKEN-9988', 'TOKEN-9988'],
            'aws style signature' => ['https://b.s3.test/f?X-Amz-Signature=deadbeef01&X-Amz-Expires=60', 'deadbeef01'],
            'credential label in text' => ["Your login\nPassword: hunter2\nBye", 'hunter2'],
            'credential label in html' => ['<p><b>Password:</b> hunter2</p>', 'hunter2'],
            'label with equals' => ['api_key = sk_live_123456', 'sk_live_123456'],
        ];
    }

    #[DataProvider('secrets')]
    public function test_the_secret_is_removed(string $input, string $secret): void
    {
        $result = MailRedactor::apply($input);

        $this->assertStringNotContainsString($secret, (string) $result->text);
        $this->assertStringContainsString(MailRedactor::PLACEHOLDER, (string) $result->text);
        $this->assertTrue($result->redacted);
    }

    public function test_the_surrounding_text_and_other_parameters_stay(): void
    {
        $result = MailRedactor::apply('<a href="https://x.test/v?id=7&amp;signature=abc&amp;lang=uk">go</a>');

        $this->assertSame('<a href="https://x.test/v?id=7&amp;signature=[REDACTED]&amp;lang=uk">go</a>', $result->text);
    }

    public function test_the_reset_path_keeps_the_prefix_and_drops_only_the_token(): void
    {
        $this->assertSame(
            'https://x.test/reset-password/[REDACTED]?email=a',
            MailRedactor::apply('https://x.test/reset-password/tok123?email=a')->text,
        );
    }

    public function test_a_parameter_that_only_ends_with_a_listed_name_is_not_touched(): void
    {
        $result = MailRedactor::apply('https://x.test/?monkey=1&hotkey=2&page=3');

        $this->assertSame('https://x.test/?monkey=1&hotkey=2&page=3', $result->text);
        $this->assertFalse($result->redacted);
    }

    public function test_clean_text_is_reported_as_not_redacted(): void
    {
        $result = MailRedactor::apply('Hello, your order #5 has shipped.');

        $this->assertSame('Hello, your order #5 has shipped.', $result->text);
        $this->assertFalse($result->redacted);
    }

    public function test_null_and_empty_pass_through(): void
    {
        $this->assertNull(MailRedactor::apply(null)->text);
        $this->assertSame('', MailRedactor::apply('')->text);
        $this->assertFalse(MailRedactor::apply('')->redacted);
    }

    public function test_it_is_idempotent(): void
    {
        $once = MailRedactor::apply('https://x.test/v?signature=abc')->text;

        $this->assertSame($once, MailRedactor::apply($once)->text);
    }

    public function test_the_parameter_list_is_configurable(): void
    {
        config()->set('filament-mail-log.redaction.query_parameters', ['magic']);

        $this->assertSame('https://x.test/?magic=[REDACTED]&signature=keep', MailRedactor::apply('https://x.test/?magic=1&signature=keep')->text);
    }

    public function test_extra_patterns_are_applied(): void
    {
        config()->set('filament-mail-log.redaction.patterns', ['~\bINV-\d{6}\b~']);

        $this->assertSame('Invoice [REDACTED] attached', MailRedactor::apply('Invoice INV-123456 attached')->text);
    }

    public function test_path_prefixes_are_configurable(): void
    {
        config()->set('filament-mail-log.redaction.path_prefixes', ['claim']);

        $this->assertSame('https://x.test/claim/[REDACTED]', MailRedactor::apply('https://x.test/claim/abc')->text);
    }

    public function test_a_broken_pattern_fails_closed(): void
    {
        config()->set('filament-mail-log.redaction.patterns', ['~(unclosed']);

        $result = MailRedactor::apply('https://x.test/?a=1 plain text');

        $this->assertSame(MailRedactor::FAILED, $result->text);
        $this->assertTrue($result->redacted);
    }

    public function test_a_pattern_that_hits_the_backtrack_limit_fails_closed(): void
    {
        config()->set('filament-mail-log.redaction.patterns', ['~(a+)+$~']);
        ini_set('pcre.backtrack_limit', '1000');

        try {
            $result = MailRedactor::apply(str_repeat('a', 5000).'b');
        } finally {
            ini_restore('pcre.backtrack_limit');
        }

        $this->assertSame(MailRedactor::FAILED, $result->text);
    }

    public function test_clean_returns_the_text_only(): void
    {
        $this->assertSame('Reset: https://x.test/reset-password/[REDACTED]', MailRedactor::clean('Reset: https://x.test/reset-password/abc'));
        $this->assertNull(MailRedactor::clean(null));
    }
}
