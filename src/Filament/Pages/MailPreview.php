<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Filament\Pages;

use Asignua\FilamentMailLog\MailLogPlugin;
use BackedEnum;
use Closure;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Renderable;
use Throwable;
use UnitEnum;

/**
 * Renders the messages registered with `MailLogPlugin::previews()` so a designer can see a mail in every
 * language without sending it. Only registered keys can be rendered; a Livewire property is never trusted.
 */
class MailPreview extends Page
{
    protected string $view = 'filament-mail-log::pages.mail-preview';

    protected static ?string $slug = 'mail-preview';

    public string $preview = '';

    public string $locale = '';

    public static function canAccess(): bool
    {
        return MailLogPlugin::isActive()
            && MailLogPlugin::get()->canAccess()
            && MailLogPlugin::get()->getPreviews() !== [];
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-mail-log::filament-mail-log.preview.navigation');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return MailLogPlugin::isActive() ? MailLogPlugin::get()->getNavigationGroup() : null;
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedEnvelopeOpen;
    }

    public function getTitle(): string
    {
        return __('filament-mail-log::filament-mail-log.preview.title');
    }

    public function mount(): void
    {
        $this->preview = (string) (array_key_first($this->previews()) ?? '');
        $this->locale = $this->locales()[0] ?? (string) config('app.locale');
    }

    /**
     * @return array<string, string>
     */
    public function previewOptions(): array
    {
        $keys = array_keys($this->previews());

        return array_combine($keys, $keys);
    }

    /**
     * @return array<string, string>
     */
    public function localeOptions(): array
    {
        $locales = $this->locales();

        return array_combine($locales, $locales);
    }

    /**
     * The rendered message, or null when the key is unknown / the message can not be built.
     */
    public function html(): ?string
    {
        $previews = $this->previews();

        if (!array_key_exists($this->preview, $previews) || !in_array($this->locale, $this->locales(), true)) {
            return null;
        }

        $original = app()->getLocale();
        app()->setLocale($this->locale);

        try {
            $source = $previews[$this->preview];
            $source = $source instanceof Closure ? app()->call($source) : $source;
            $source = is_string($source) ? app($source) : $source;

            return $source instanceof Renderable ? $source->render() : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        } finally {
            app()->setLocale($original);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function previews(): array
    {
        // Checked on every Livewire request, not only at mount: access can be revoked mid-session.
        return MailLogPlugin::isActive() && MailLogPlugin::get()->canAccess()
            ? MailLogPlugin::get()->getPreviews()
            : [];
    }

    /**
     * @return list<string>
     */
    private function locales(): array
    {
        return MailLogPlugin::get()->getPreviewLocales();
    }
}
