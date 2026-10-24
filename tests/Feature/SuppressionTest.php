<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Tests\Feature;

use Asignua\FilamentMailLog\Enums\MailStatus;
use Asignua\FilamentMailLog\Enums\SuppressionReason;
use Asignua\FilamentMailLog\Filament\Resources\Suppressions\MailSuppressionResource;
use Asignua\FilamentMailLog\Filament\Resources\Suppressions\Pages\ListMailSuppressions;
use Asignua\FilamentMailLog\Listeners\LogOutgoingMail;
use Asignua\FilamentMailLog\MailLogPlugin;
use Asignua\FilamentMailLog\Models\MailLog;
use Asignua\FilamentMailLog\Models\MailSuppression;
use Asignua\FilamentMailLog\Repositories\DeliveryRepository;
use Asignua\FilamentMailLog\Support\MailLogContext;
use Asignua\FilamentMailLog\Tests\Fixtures\WelcomeMail;
use Asignua\FilamentMailLog\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Workbench\App\Models\User;

class SuppressionTest extends TestCase
{
    private function suppress(string $email, SuppressionReason $reason = SuppressionReason::HardBounce): MailSuppression
    {
        app(DeliveryRepository::class)->suppress($email, $reason, 'postmark', null, 'unknown user');

        return MailSuppression::query()->where('email', strtolower($email))->firstOrFail();
    }

    /**
     * @return list<string>
     */
    private function sentTo(): array
    {
        $message = Mail::getSymfonyTransport()->messages()->last();

        return array_map(fn ($address): string => $address->getAddress(), $message?->getEnvelope()->getRecipients() ?? []);
    }

    public function test_it_is_off_by_default_and_everything_is_sent(): void
    {
        $this->suppress('ann@example.test');

        Mail::to('ann@example.test')->send(new WelcomeMail);

        $this->assertSame(['ann@example.test'], $this->sentTo());
        $this->assertSame(MailStatus::Sent, MailLog::query()->sole()->status);
    }

    public function test_a_message_to_only_suppressed_addresses_is_not_sent_and_its_row_says_so(): void
    {
        config()->set('filament-mail-log.suppression.enabled', true);
        $this->suppress('Ann@Example.test');

        $result = Mail::to('ann@example.test')->send(new WelcomeMail);

        $this->assertNull($result);
        $this->assertCount(0, Mail::getSymfonyTransport()->messages());

        $log = MailLog::query()->sole();
        $this->assertSame(MailStatus::Suppressed, $log->status);
        $this->assertStringContainsString('ann@example.test', (string) $log->error);

        // The end of the request must not turn the deliberate cancel into "never answered".
        app(LogOutgoingMail::class)->failUnconfirmed();
        $this->assertSame(MailStatus::Suppressed, $log->fresh()->status);
        $this->assertTrue(MailLogContext::isEmpty());
    }

    public function test_only_the_suppressed_recipients_are_dropped(): void
    {
        config()->set('filament-mail-log.suppression.enabled', true);
        $this->suppress('bad@example.test');
        $this->suppress('angry@example.test', SuppressionReason::Complaint);

        Mail::to(['good@example.test', 'bad@example.test'])->cc(['angry@example.test', 'cc@example.test'])->bcc('bad@example.test')->send(new WelcomeMail);

        $this->assertEqualsCanonicalizing(['good@example.test', 'cc@example.test'], $this->sentTo());
        $this->assertSame(MailStatus::Sent, MailLog::query()->sole()->status);

        $headers = Mail::getSymfonyTransport()->messages()->last()->getOriginalMessage()->getHeaders();
        $this->assertFalse($headers->has('Bcc'), 'an emptied list is removed, not left as an empty header');
        $this->assertStringNotContainsString('bad@example.test', $headers->get('To')->getBodyAsString());
    }

    public function test_when_only_cc_is_left_the_message_still_goes(): void
    {
        config()->set('filament-mail-log.suppression.enabled', true);
        $this->suppress('bad@example.test');

        Mail::to('bad@example.test')->cc('cc@example.test')->send(new WelcomeMail);

        $this->assertSame(['cc@example.test'], $this->sentTo());
    }

    public function test_other_recipients_are_untouched(): void
    {
        config()->set('filament-mail-log.suppression.enabled', true);
        $this->suppress('bad@example.test');

        Mail::to('fine@example.test')->send(new WelcomeMail);

        $this->assertSame(['fine@example.test'], $this->sentTo());
    }

    public function test_an_address_is_listed_once_and_keeps_its_first_reason(): void
    {
        $this->suppress('ann@example.test', SuppressionReason::HardBounce);
        $this->suppress('ANN@example.test', SuppressionReason::Complaint);

        $this->assertSame(SuppressionReason::HardBounce, MailSuppression::query()->sole()->reason);
    }

    public function test_recording_can_be_switched_off(): void
    {
        config()->set('filament-mail-log.suppression.record', false);
        config()->set('filament-mail-log.webhooks.providers.postmark', ['driver' => \Asignua\FilamentMailLog\Webhooks\Drivers\PostmarkDriver::class, 'enabled' => true, 'username' => 'u', 'password' => 'p']);

        $this->call('POST', '/mail-log/webhooks/postmark', [], [], [], $this->transformHeadersToServerVars(['Authorization' => 'Basic '.base64_encode('u:p'), 'Content-Type' => 'application/json']), (string) json_encode([
            'RecordType' => 'Bounce', 'ID' => 1, 'Type' => 'HardBounce', 'MessageID' => 'm', 'Email' => 'ann@example.test', 'BouncedAt' => '2026-10-20T10:00:00Z',
        ]))->assertOk();

        $this->assertSame(0, MailSuppression::query()->count());
    }

    public function test_the_list_needs_log_access_and_removing_needs_its_own_permission(): void
    {
        $row = $this->suppress('ann@example.test');

        $this->assertFalse(MailSuppressionResource::canViewAny());

        Gate::define('viewMailLog', fn (User $user): bool => true);
        $this->assertTrue(MailSuppressionResource::canViewAny());
        $this->assertFalse(MailLogPlugin::get()->canManageSuppressions());

        Livewire::test(ListMailSuppressions::class)
            ->assertCanSeeTableRecords([$row])
            ->assertTableActionHidden('delete', $row);

        Gate::define(MailLogPlugin::SUPPRESSIONS_ABILITY, fn (User $user): bool => true);

        Livewire::test(ListMailSuppressions::class)
            ->assertTableActionVisible('delete', $row)
            ->callTableAction('delete', $row);

        $this->assertSame(0, MailSuppression::query()->count());
    }

    public function test_a_crafted_remove_call_is_refused_without_the_permission(): void
    {
        Gate::define('viewMailLog', fn (User $user): bool => true);
        $row = $this->suppress('ann@example.test');

        try {
            Livewire::test(ListMailSuppressions::class)->callTableAction('delete', $row);
        } catch (\Throwable) {
            // Hidden actions refuse the call one way or another.
        }

        $this->assertSame(1, MailSuppression::query()->count());
    }

    public function test_a_callback_wins_over_the_gate(): void
    {
        Gate::define('viewMailLog', fn (User $user): bool => true);
        Gate::define(MailLogPlugin::SUPPRESSIONS_ABILITY, fn (User $user): bool => true);

        MailLogPlugin::get()->authorizeSuppressions(false);

        $this->assertFalse(MailLogPlugin::get()->canManageSuppressions());
        $this->assertFalse(MailSuppressionResource::canDeleteAny());
    }
}
