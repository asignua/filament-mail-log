<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog;

use Asignua\FilamentMailLog\Filament\Pages\MailPreview;
use Asignua\FilamentMailLog\Filament\Resources\MailLogs\MailLogResource;
use BackedEnum;
use Closure;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * The panel side of the log: the "Mail log" resource and the optional "Mail preview" page.
 *
 *     ->plugin(MailLogPlugin::make()
 *         ->authorize(fn (): bool => auth()->user()?->isAdmin() === true)
 *         ->navigationGroup('System')
 *         ->previews(['Welcome' => fn () => new WelcomeMail(User::factory()->make())]))
 *
 * Logging itself (listeners, the prune command, the schedule) does not depend on the panel.
 */
class MailLogPlugin implements Plugin
{
    public const string ID = 'asignua-filament-mail-log';

    /** The gate ability consulted when no `authorize()` was given. */
    public const string ABILITY = 'viewMailLog';

    protected bool|Closure|null $authorize = null;

    protected bool $resource = true;

    protected bool $previewPage = true;

    /** @var array<string, class-string<Mailable>|Closure|Mailable|Renderable>|Closure */
    protected array|Closure $previews = [];

    /** @var list<string>|null */
    protected ?array $previewLocales = null;

    protected string|UnitEnum|Closure|null $navigationGroup = null;

    protected ?int $navigationSort = null;

    protected string|BackedEnum|Closure|null $navigationIcon = null;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static */
        return filament(static::ID);
    }

    /**
     * True when the CURRENT panel has the plugin; resources and pages ask this before touching it.
     */
    public static function isActive(): bool
    {
        return Filament::getCurrentPanel()?->hasPlugin(static::ID) === true;
    }

    public function getId(): string
    {
        return static::ID;
    }

    /**
     * Who may open the log. A stored message can hold a one-time code or a private conversation, so with no
     * callback the answer comes from the `viewMailLog` gate ability, and when the gate is not defined,
     * from NOBODY. `->authorize(true)` lets every panel user in.
     */
    public function authorize(bool|Closure|null $callback): static
    {
        $this->authorize = $callback;

        return $this;
    }

    /**
     * Show the "Mail log" resource (on by default).
     */
    public function resource(bool $condition = true): static
    {
        $this->resource = $condition;

        return $this;
    }

    /**
     * Register the messages that the "Mail preview" page can render for designers. The key is the label;
     * a value is a mailable instance, a mailable class (built by the container), a Renderable, or a closure
     * returning any of those. The page shows nothing that is not registered here.
     *
     * @param array<string, class-string<Mailable>|Closure|Mailable|Renderable>|Closure $previews
     */
    public function previews(array|Closure $previews): static
    {
        $this->previews = $previews;

        return $this;
    }

    /**
     * Show the "Mail preview" page when previews are registered (on by default).
     */
    public function previewPage(bool $condition = true): static
    {
        $this->previewPage = $condition;

        return $this;
    }

    /**
     * Languages offered by the preview page. Default: the application's locale and fallback locale.
     *
     * @param list<string> $locales
     */
    public function previewLocales(array $locales): static
    {
        $this->previewLocales = $locales;

        return $this;
    }

    public function navigationGroup(string|UnitEnum|Closure|null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function navigationSort(?int $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function navigationIcon(string|BackedEnum|Closure|null $icon): static
    {
        $this->navigationIcon = $icon;

        return $this;
    }

    public function canAccess(): bool
    {
        if ($this->authorize !== null) {
            return $this->authorize instanceof Closure ? (bool) app()->call($this->authorize) : $this->authorize;
        }

        return Gate::has(static::ABILITY) && Gate::allows(static::ABILITY);
    }

    public function getNavigationGroup(): string|UnitEnum|null
    {
        return value($this->navigationGroup);
    }

    public function getNavigationSort(): ?int
    {
        return $this->navigationSort;
    }

    public function getNavigationIcon(): string|BackedEnum|null
    {
        return value($this->navigationIcon);
    }

    /**
     * @return array<string, class-string<Mailable>|Closure|Mailable|Renderable>
     */
    public function getPreviews(): array
    {
        return value($this->previews);
    }

    /**
     * @return list<string>
     */
    public function getPreviewLocales(): array
    {
        if ($this->previewLocales !== null) {
            return $this->previewLocales;
        }

        return array_values(array_unique(array_filter([
            (string) config('app.locale'),
            (string) config('app.fallback_locale'),
        ])));
    }

    public function register(Panel $panel): void
    {
        if ($this->resource) {
            $panel->resources([MailLogResource::class]);
        }

        if ($this->previewPage) {
            $panel->pages([MailPreview::class]);
        }
    }

    public function boot(Panel $panel): void {}
}
