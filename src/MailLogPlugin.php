<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog;

use Asignua\FilamentMailLog\Filament\Pages\MailPreview;
use Asignua\FilamentMailLog\Filament\Resources\MailLogs\MailLogResource;
use Asignua\FilamentMailLog\Filament\Resources\Suppressions\MailSuppressionResource;
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

    /** The gate ability consulted for "Resend" when no `authorizeResend()` was given. */
    public const string RESEND_ABILITY = 'resendMailLog';

    protected bool|Closure|null $authorize = null;

    /** The gate ability consulted for removing an address from the suppression list when no `authorizeSuppressions()` was given. */
    public const string SUPPRESSIONS_ABILITY = 'manageMailSuppressions';

    protected bool|Closure|null $authorizeResend = null;

    protected bool|Closure|null $authorizeSuppressions = null;

    protected bool $suppressionResource = true;

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
     * Who may resend a stored message. Resending is an outward action (it mails a third party), so it is a
     * separate, closed-by-default permission: seeing the log does NOT imply it. With no callback the answer
     * comes from the `resendMailLog` gate ability and, when it is not defined, from NOBODY. The user must
     * also pass {@see self::canAccess()}.
     */
    public function authorizeResend(bool|Closure|null $callback): static
    {
        $this->authorizeResend = $callback;

        return $this;
    }

    /**
     * Who may REMOVE an address from the suppression list (seeing the list needs only access to the log). Removing
     * mails an address that bounced or complained again, so it is closed by default: with no callback the answer
     * comes from the `manageMailSuppressions` gate ability and, when it is not defined, from NOBODY.
     */
    public function authorizeSuppressions(bool|Closure|null $callback): static
    {
        $this->authorizeSuppressions = $callback;

        return $this;
    }

    /**
     * Show the "Suppressions" resource (on by default; it still needs the suppressions table).
     */
    public function suppressionResource(bool $condition = true): static
    {
        $this->suppressionResource = $condition;

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

    public function canResend(): bool
    {
        if (!$this->canAccess() || !(bool) config('filament-mail-log.resend.enabled', true)) {
            return false;
        }

        if ($this->authorizeResend !== null) {
            return $this->authorizeResend instanceof Closure ? (bool) app()->call($this->authorizeResend) : $this->authorizeResend;
        }

        return Gate::has(static::RESEND_ABILITY) && Gate::allows(static::RESEND_ABILITY);
    }

    public function canManageSuppressions(): bool
    {
        if (!$this->canAccess()) {
            return false;
        }

        if ($this->authorizeSuppressions !== null) {
            return $this->authorizeSuppressions instanceof Closure ? (bool) app()->call($this->authorizeSuppressions) : $this->authorizeSuppressions;
        }

        return Gate::has(static::SUPPRESSIONS_ABILITY) && Gate::allows(static::SUPPRESSIONS_ABILITY);
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

        if ($this->suppressionResource) {
            $panel->resources([MailSuppressionResource::class]);
        }

        if ($this->previewPage) {
            $panel->pages([MailPreview::class]);
        }
    }

    public function boot(Panel $panel): void {}
}
