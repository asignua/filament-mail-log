<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Tests\Unit;

use Asignua\FilamentMailLog\Support\MailRedactor;
use Asignua\FilamentMailLog\Support\SafeFrame;
use Asignua\FilamentMailLog\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Independent review (08.10.2026): redaction edge cases beyond the author's suite.
 *
 * Tests named `test_bug_*` document defects found in the review; they FAIL until the redactor is fixed.
 */
class ReviewRedactorEdgeCasesTest extends TestCase
{
    /**
     * Cases the redactor already handles; kept as regression guards.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function handled(): array
    {
        return [
            'laravel verify-email link in markdown subcopy' => [
                '<span class="break-all">http://localhost/email/verify/1/9f86d081884c7d659a2feaa0c55ad015a3bf4f1b?expires=1&amp;signature=5e884898da28047151d0e56f8dc6292773603d0d6aabbdd62a11ef721d1542d8</span>',
                '5e884898da28047151d0e56f8dc6292773603d0d6aabbdd62a11ef721d1542d8',
            ],
            'laravel reset link with query' => ['http://localhost/reset-password/abcDEF123456?email=ann%40example.test', 'abcDEF123456'],
            'list-unsubscribe header' => ['<https://x.test/unsubscribe/UNSUBTOKEN123>', 'UNSUBTOKEN123'],
            'single-quoted href' => ["<a href='https://x.test/v?signature=SQSECRET'>x</a>", 'SQSECRET'],
            'parameter in a spa fragment after ?' => ['https://app.test/#/reset?token=FRAGSECRET', 'FRAGSECRET'],
            'label in a table cell' => ['<td>Password:</td><td>hunter2</td>', 'hunter2'],
            'otp label' => ['Your OTP: 845512', '845512'],
        ];
    }

    #[DataProvider('handled')]
    public function test_handled_secret_is_removed(string $input, string $secret): void
    {
        $this->assertStringNotContainsString($secret, (string) MailRedactor::clean($input));
    }

    public function test_bug_a_password_after_nbsp_entity_leaks(): void
    {
        // HTML mail templates routinely put `&nbsp;` between a label and its value. The value class stops at `;`,
        // so only `&nbsp` is replaced and the password itself survives.
        $out = (string) MailRedactor::clean('<p>Password:&nbsp;hunter2</p>');

        $this->assertStringNotContainsString('hunter2', $out);
    }

    public function test_bug_a_password_with_punctuation_leaks_its_tail(): void
    {
        // Generated passwords contain `,` `;` `'` `"`; everything after the first such character is stored.
        $out = (string) MailRedactor::clean("Password: Xy7,Q9;Zp'w\"K");

        $this->assertStringNotContainsString('Q9', $out);
        $this->assertStringNotContainsString('Zp', $out);
    }

    public function test_bug_a_signed_url_nested_url_encoded_in_a_redirect_parameter_leaks(): void
    {
        // `?redirect=` / `?next=` / `?intended=` links carry the inner signed URL percent-encoded.
        $out = (string) MailRedactor::clean(
            'https://x.test/login?redirect=https%3A%2F%2Fx.test%2Fverify%3Fexpires%3D1%26signature%3DENCSECRET',
        );

        $this->assertStringNotContainsString('ENCSECRET', $out);
    }

    public function test_bug_a_token_in_a_url_fragment_after_hash_leaks(): void
    {
        // Magic links of SPA / Supabase-style auth put the token right after `#`.
        $out = (string) MailRedactor::clean('https://app.test/auth/callback#access_token=FRAGTOKEN&type=magiclink');

        $this->assertStringNotContainsString('FRAGTOKEN', $out);
    }

    public function test_safe_frame_puts_the_csp_before_the_doctype_which_switches_the_preview_to_quirks_mode(): void
    {
        // Documented behaviour, low severity: a `<meta>` before `<!DOCTYPE html>` makes the parser ignore the doctype,
        // so the preview renders in quirks mode (tables / box sizing differ from what a mail client shows).
        $doc = SafeFrame::document('<!DOCTYPE html><html><head></head><body>x</body></html>');

        $this->assertStringStartsWith('<meta http-equiv="Content-Security-Policy"', $doc);
    }
}
