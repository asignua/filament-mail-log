# Filament Mail Log

[![Tests](https://img.shields.io/github/actions/workflow/status/asignua/filament-mail-log/tests.yml?branch=main&label=tests)](https://github.com/asignua/filament-mail-log/actions/workflows/tests.yml)

A read-only log of every message your Laravel application sends, inside a Filament 5 panel. It answers "did the
customer get the email, and what did it say?" without a third-party service: each message is followed from the
queue to the transport (`queued`, `sending`, `sent`, `failed`), stored with its recipients, headers, bodies and
attachment names, and shown in a sandboxed preview.

Secrets never reach the table: signed-link signatures, tokens, password-reset URLs and "Password: ..." lines are
redacted **before** the copy is stored.

## Screenshots

_Screenshots: TODO (add images to `art/`, `cover.jpg` first, and reference them here)._

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- Filament 5

## Installation

```bash
composer require asignua/filament-mail-log
```

Register the plugin on your panel:

```php
use Asignua\FilamentMailLog\MailLogPlugin;

$panel->plugin(MailLogPlugin::make());
```

Publish and run the migration (the table name and connection come from the config, so decide before migrating):

```bash
php artisan vendor:publish --tag=filament-mail-log-migrations
php artisan migrate
```

**Access is closed until you open it.** A stored mail can hold a one-time code or a private conversation, so with
no setup nobody sees the log. Either define the gate ability:

```php
Gate::define('viewMailLog', fn (User $user): bool => $user->isAdmin());
```

or pass a callback to the plugin (it wins over the gate):

```php
MailLogPlugin::make()->authorize(fn (): bool => auth()->user()?->isAdmin() === true);
```

## Usage

Logging starts as soon as the package is installed; no code changes. Every `Mail::send()`, queued mailable and
mail notification is recorded.

| Status | Meaning |
| --- | --- |
| `queued` | pushed to a queue (database, redis, ...), no worker took it yet |
| `sending` | handed to the mailer, the transport has not answered |
| `sent` | the transport accepted it (stores the message id) |
| `failed` | the job threw, or the transport threw; the error is stored |

The list filters by status, type, recipient (To/Cc/Bcc), period and text in the body. The view page has
**Details**, **HTML** (sandboxed iframe), **Plain text** and **Headers** tabs.

### Mail preview for designers

Register the messages and the page appears (same access rule as the log):

```php
MailLogPlugin::make()
    ->previews([
        'Welcome' => fn () => new WelcomeMail(User::factory()->make()),
        'Invoice' => InvoiceMail::class,           // built by the container
    ])
    ->previewLocales(['en', 'uk']);                // default: app locale + fallback
```

Only registered keys can be rendered. The locale is switched for the render and restored afterwards.

### Retention

`mail-log:prune` deletes records older than `retention_days` (default 90, `0` = keep forever) in small chunks and
marks `sending` rows nobody confirmed for `stale_after_minutes` as failed. It is scheduled daily at 03:40; set
`MAIL_LOG_SCHEDULE=false` to schedule it yourself.

## Configuration

Fluent setters on `MailLogPlugin`: `authorize()`, `resource()`, `previews()`, `previewPage()`, `previewLocales()`,
`navigationGroup()`, `navigationSort()`, `navigationIcon()`.

Everything else is in the config (`php artisan vendor:publish --tag=filament-mail-log-config`):

| Key | Default | |
| --- | --- | --- |
| `enabled` | `true` (`MAIL_LOG_ENABLED`) | master switch for logging |
| `connection`, `table` | default connection, `mail_logs` | where the log lives |
| `retention_days` | `90` | used by `mail-log:prune` |
| `schedule.enabled`, `schedule.time` | `true`, `03:40` | the prune schedule |
| `body.store`, `body.max_bytes` | `true`, `200000` | keep bodies; cut at this many bytes (before redaction) |
| `headers.store`, `headers.ignore` | `true`, auth-like headers | the Headers tab |
| `attachments.sizes` | `true` | store attachment sizes (reads the attachment once more) |
| `redaction.*` | see below | what is removed from stored text |
| `types` | `[]` | `[WelcomeMail::class => 'Welcome mail']` labels; subclasses inherit |
| `preview.csp` | restrictive | Content-Security-Policy inside the preview iframe (no remote images) |
| `preview.csp_remote_images` | allows `https:` images | policy after "Load remote images" |
| `isolate_connection` | `true` | write the log on a separate connection (see Gotchas) |

### Redaction

Applied to the bodies, the subject, the header values and the error text:

- `redaction.query_parameters`: the value of `signature`, `token`, `code`, `api_key`, ... in any URL becomes
  `[REDACTED]` (also inside HTML, where `&amp;` separates parameters).
- `redaction.path_prefixes`: `/reset-password/{token}` becomes `/reset-password/[REDACTED]` (also
  `verify-email`, `magic-link`, `invitation`, ...).
- `redaction.credential_labels`: `Password: hunter2` keeps the label and drops the value.
- `redaction.patterns`: your own regexes (the whole match is replaced). Add the `u` flag (`~…~iu`) to anything that touches non-ASCII text: without it `\b` and `/i` do not see UTF-8 letters. The built-in rules and `credential_labels` (en, uk/ru, de, pl, es) are already UTF-8 safe.

It fails closed: a pattern that breaks at run time (PCRE backtrack limit, an invalid regex) replaces the whole text
with `[redaction failed]`. Add the parameters of your own signed links to the list; the defaults can not know them.

## Gotchas

- **A mail is missing from the log.** The log lives in the process that sends: a message built with a custom
  transport that bypasses `Illuminate\Mail\Mailer` (raw Symfony mailer) fires no events. Check `MAIL_LOG_ENABLED` and
  that the migration ran: a missing table is swallowed on purpose, because the log must never stop the mail
  (look for `filament-mail-log: could not record` in your log).
- **A row stays `sending`.** The transport threw outside a queue job and the process was killed, or a worker was
  killed by its timeout. `mail-log:prune` fails such rows after `stale_after_minutes`.
- **The recipient sees an `X-Mail-Log-Id` header.** It is how `MessageSending` is tied to `MessageSent` (Symfony
  clones the message). It is a random ULID and carries nothing; rename it with the `header` config.
- **Stored mail is still personal data.** Redaction removes secrets, not names or order contents. Use
  `body.store=false` or a short `retention_days` where that matters.
- **Mail sent inside a DB transaction keeps its log row.** With `connection` = null the log is written on a separate connection that clones the default one (`isolate_connection`), so a rollback does not erase the record of a mail that already left. In-memory SQLite stays on the default connection. Side effect: a host test wrapped in a transaction does not roll the log rows back.
- **A message cancelled by another `MessageSending` listener** (one that returns `false`) is logged as failed with the text "cancelled by a listener, the transport threw an exception, or the process ended", because the log row is written before the other listeners run.
- **Supported databases:** MySQL / MariaDB, PostgreSQL and SQLite (recipient and body searches use `LIKE ... ESCAPE`, JSON columns are cast to text on PostgreSQL).
- **Queue correlation** uses the queue connection together with the job id; a job that finishes without sending closes its `queued` row as failed, and a job that will be retried keeps it for the next attempt.
- **Attachment sizes** are read only from string and file bodies; a stream attachment is never read (that would consume it), so its size stays empty.
- **The preview blocks remote images.** Opening a message must not fire its open-tracking pixels (that would mark it as opened and leak the admin's IP), so the policy allows `data:` images only. The "Load remote images" button on the message page switches to `preview.csp_remote_images` for that view.
- **Search finds nothing in the body.** The body is a long text column, so it has its own filter (not the global
  search); it uses `LIKE`, there is no full-text index.

## Translations

The interface ships in English and Ukrainian (other locales fall back to English) under the `filament-mail-log::filament-mail-log` namespace. A test keeps every language in step with the English keys. Override a
string by publishing the translations (`--tag=filament-mail-log-translations`) and editing the copy in
`lang/vendor/filament-mail-log`.

## AI agents

The package ships [Laravel Boost](https://laravel.com/docs/boost) guidelines (`resources/boost/guidelines/core.blade.php`) so a
coding agent wires it up correctly.

## Testing

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pint --test
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
