<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Tests\Feature;

use Asignua\FilamentMailLog\Filament\Pages\MailPreview;
use Asignua\FilamentMailLog\MailLogPlugin;
use Asignua\FilamentMailLog\Tests\Fixtures\WelcomeMail;
use Asignua\FilamentMailLog\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Workbench\App\Models\User;

class MailPreviewTest extends TestCase
{
    public function test_the_page_is_hidden_when_nothing_is_registered(): void
    {
        $this->allow();

        $this->assertFalse(MailPreview::canAccess());
        $this->get('/admin/mail-preview')->assertForbidden();
    }

    public function test_the_page_follows_the_same_access_gate(): void
    {
        $this->plugin()->previews(['Welcome' => fn () => new WelcomeMail]);

        $this->assertFalse(MailPreview::canAccess());

        $this->allow();

        $this->assertTrue(MailPreview::canAccess());
        $this->get('/admin/mail-preview')->assertOk();
    }

    public function test_it_renders_a_registered_mailable_in_a_sandboxed_frame(): void
    {
        $this->allow();
        $this->plugin()->previews([
            'Welcome' => fn () => new WelcomeMail('<p>Hello preview</p>'),
            'Other' => WelcomeMail::class,
        ]);

        $html = Livewire::test(MailPreview::class)->assertSet('preview', 'Welcome')->html();

        $this->assertStringContainsString('sandbox=""', $html);
        $this->assertStringContainsString('Hello preview', $html);
    }

    public function test_a_class_name_is_built_by_the_container(): void
    {
        $this->allow();
        $this->plugin()->previews(['Plain' => WelcomeMail::class]);

        $this->assertStringContainsString('Hello', (string) Livewire::test(MailPreview::class)->instance()->html());
    }

    public function test_an_unregistered_key_renders_nothing(): void
    {
        $this->allow();
        $this->plugin()->previews(['Welcome' => fn () => new WelcomeMail]);

        $page = Livewire::test(MailPreview::class)->set('preview', 'Illuminate\\Foundation\\Inspiring');

        $this->assertNull($page->instance()->html());
        $page->assertSee(__('filament-mail-log::filament-mail-log.preview.unavailable'));
    }

    public function test_an_unlisted_locale_renders_nothing(): void
    {
        $this->allow();
        $this->plugin()->previews(['Welcome' => fn () => new WelcomeMail])->previewLocales(['en']);

        $page = Livewire::test(MailPreview::class)->set('locale', '../../etc');

        $this->assertNull($page->instance()->html());
    }

    public function test_the_locale_is_applied_while_rendering_and_restored_after(): void
    {
        $this->allow();
        $seen = [];
        $this->plugin()
            ->previewLocales(['en', 'uk'])
            ->previews(['Welcome' => function () use (&$seen): WelcomeMail {
                $seen[] = app()->getLocale();

                return new WelcomeMail;
            }]);

        $before = app()->getLocale();
        Livewire::test(MailPreview::class)->set('locale', 'uk')->assertSet('locale', 'uk');

        $this->assertContains('uk', $seen);
        $this->assertSame($before, app()->getLocale());
    }

    public function test_a_failing_preview_is_reported_not_thrown_and_the_locale_is_restored(): void
    {
        $this->allow();
        $this->plugin()->previewLocales(['en', 'uk'])->previews(['Broken' => function (): never {
            throw new \RuntimeException('no data');
        }]);

        $before = app()->getLocale();
        $page = Livewire::test(MailPreview::class)->set('locale', 'uk');

        $this->assertNull($page->instance()->html());
        $this->assertSame($before, app()->getLocale());
    }

    public function test_revoking_access_mid_session_stops_the_rendering(): void
    {
        $this->allow();
        $this->plugin()->previews(['Welcome' => fn () => new WelcomeMail]);

        $page = Livewire::test(MailPreview::class);
        $this->plugin()->authorize(false);

        $this->assertNull($page->instance()->html());
    }

    public function test_the_page_can_be_switched_off(): void
    {
        $panel = \Filament\Panel::make()->id('other')->path('other');
        MailLogPlugin::make()->previewPage(false)->register($panel);

        $this->assertNotContains(MailPreview::class, $panel->getPages());
    }

    public function test_default_locales_are_the_app_and_fallback_locale(): void
    {
        config()->set('app.locale', 'uk');
        config()->set('app.fallback_locale', 'en');

        $this->assertSame(['uk', 'en'], MailLogPlugin::make()->getPreviewLocales());
    }

    private function plugin(): MailLogPlugin
    {
        return MailLogPlugin::get();
    }

    private function allow(): void
    {
        Gate::define('viewMailLog', fn (User $user): bool => true);
    }
}
