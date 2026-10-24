# Filament Mail Log

[![Stand With Ukraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://stand-with-ukraine.pp.ua)

<img class="filament-hidden" src="https://raw.githubusercontent.com/asignua/filament-mail-log/v1.0.1/art/cover.jpg" alt="Filament Mail Log">

[![Tests](https://img.shields.io/github/actions/workflow/status/asignua/filament-mail-log/tests.yml?branch=main&label=tests)](https://github.com/asignua/filament-mail-log/actions/workflows/tests.yml)

A log of every message your Laravel application sends, inside a Filament 5 panel. It answers "did the
customer get the email, and what did it say?" without a third-party service: each message is followed from the
queue to the transport (`queued`, `sending`, `sent`, `failed`), stored with its recipients, headers, bodies and
attachment names, and shown in a sandboxed preview.

Secrets never reach the table: signed-link signatures, tokens, password-reset URLs and "Password: ..." lines are
redacted **before** the copy is stored.

## Screenshots

Every message with its status (queued, sent or failed), recipient, subject and type:

![The mail log table with sent, queued and failed messages](https://raw.githubusercontent.com/asignua/filament-mail-log/v1.0.1/art/list.jpg)

A failed message keeps the transport error, the recipients and the timeline:

![A failed message with the SMTP error](https://raw.githubusercontent.com/asignua/filament-mail-log/v1.0.1/art/view-failed.jpg)

Signed-link signatures and reset tokens are replaced before the copy is stored:

![Plain text tab with a redacted signature and token](https://raw.githubusercontent.com/asignua/filament-mail-log/v1.0.1/art/view-text.jpg)

The HTML tab renders the stored body in a sandboxed frame (no scripts, remote images off until you ask):

![The HTML tab](https://raw.githubusercontent.com/asignua/filament-mail-log/v1.0.1/art/view-html.jpg)

Resend a stored message to the same or other recipients (separate permission, with a warning when secrets were removed):

![The Resend modal](https://raw.githubusercontent.com/asignua/filament-mail-log/v1.1.0/art/resend.jpg)

Dark mode:

![The mail log in dark mode](https://raw.githubusercontent.com/asignua/filament-mail-log/v1.0.1/art/list-dark.jpg)

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

To let people **resend** messages, open the second, separate permission (see [Resend](#resend)):

```php
Gate::define('resendMailLog', fn (User $user): bool => $user->isAdmin());
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
| `suppressed` | every recipient is on the [suppression list](#suppression-list); nothing was sent |

The list filters by status, type, recipient (To/Cc/Bcc), period and text in the body. The view page has
**Details**, **HTML** (sandboxed iframe), **Plain text** and **Headers** tabs.

### Resend

The **Resend** action (message page header and the row menu of the table) mails a stored message again, to the same
or to other recipients. It opens a confirmation modal with the original **To** addresses as editable tags
(validated as e-mail addresses), and optional **Cc** / **Bcc** toggles that are off by default.

- **Closed by default, and separate from viewing.** Resending mails a third party, so seeing the log does not allow it.
  The permission comes from `->authorizeResend(fn () => ...)` on the plugin (wins), else from the
  `resendMailLog` gate ability, else from **nobody**. The user must also be allowed to open the log.
  The permission is checked again when the modal is submitted, not only when the button is drawn.
- **It sends the stored copy.** If secrets were removed from it (`Secrets removed` is on), the modal shows a warning:
  signed links, reset tokens and passwords are `[REDACTED]` in the resent message, so those links will not work.
  Resending is still allowed (a notification, an invoice or a failed delivery is a legitimate case). The same goes for
  a body cut at `body.max_bytes`. If no body was stored (`body.store=false`), the action is hidden.
- **Attachments are not resent.** Only their names and sizes are stored, never the contents; the modal says so when the
  original had attachments.
- **What is copied:** subject, html and plain-text body, the sender, `Reply-To`, `X-Priority` and `Importance`. Other
  stored headers (ids, dates, tracking, `List-Unsubscribe` with a redacted link) are not copied.
- **The resend is logged as a new row**, linked to the original: the message page shows "Resent from #12" and
  "Resent 2 times", and the list has a "Resent from" column (hidden by default).
- Sent through `resend.mailer` (default: the application's default mailer) and, with `resend.queue`, through the queue
  (the row then starts as `queued`).

**Upgrading from 1.0:** the link lives in a new `resent_from_id` column, added by a new migration. `vendor:publish
--tag=filament-mail-log-migrations` publishes both stubs, so delete the new copy of `create_mail_logs_table` (the table
exists) and keep `add_resent_from_id_to_mail_logs_table`, then `php artisan migrate`. Until it ran, logging keeps working
and the Resend action stays hidden.

### Delivery tracking (provider webhooks)

`sent` only means the transport accepted the message. With a provider webhook the log also learns what happened
afterwards: **delivered, bounced (hard / soft), complained, opened, clicked, rejected, deferred**. Each event is
stored in `mail_log_events` and the newest meaningful one is copied to the message as `delivery_status`
(a badge column and a filter in the table, a "Delivery events" timeline on the message page).

Providers: **Postmark, Mailgun, Amazon SES (through SNS), Resend, SendGrid**. Everything is off until you switch a
provider on and give it its secret. A webhook for a provider that is off answers `404`; a missing, wrong or stale
signature answers `403`.

1. Publish and run the three new migrations (`add_delivery_status_to_mail_logs_table`, `create_mail_log_events_table`,
   `create_mail_log_suppressions_table`). Until they ran, logging and the panel work as before and the new UI is hidden.
2. Enable the provider (`.env` or the `webhooks.providers` config) and point the provider's webhook at
   `https://your-app.test/mail-log/webhooks/{provider}` (`webhooks.path`; an empty path does not register the route).
   The route has no session and no CSRF; add rate limits with `webhooks.middleware` if you want them.

| Provider | URL ends with | Env | Where to paste what |
| --- | --- | --- | --- |
| Postmark | `/postmark` | `MAIL_LOG_POSTMARK_ENABLED`, `_USERNAME`, `_PASSWORD` | Server > Webhooks: URL `https://USER:PASSWORD@your-app.test/mail-log/webhooks/postmark` (Postmark does not sign, it sends Basic auth). Tick Delivery, Bounce, Spam complaint, Open, Click |
| Mailgun | `/mailgun` | `MAIL_LOG_MAILGUN_ENABLED`, `_SIGNING_KEY` | Sending > Webhooks: add the URL for each event; the key is the "HTTP webhook signing key". JSON webhooks only |
| Amazon SES | `/ses` | `MAIL_LOG_SES_ENABLED`, `MAIL_LOG_SES_TOPIC_ARNS` | SES > Configuration set > Event destination > SNS topic; SNS > create an HTTPS subscription to the URL. The plugin confirms the subscription itself. `TOPIC_ARNS` (comma separated) is required: a valid SNS signature only proves the message came from some SNS topic |
| Resend | `/resend` | `MAIL_LOG_RESEND_WEBHOOK_ENABLED`, `_SECRET` | Webhooks > Add endpoint; the secret is the "Signing secret" (`whsec_...`) |
| SendGrid | `/sendgrid` | `MAIL_LOG_SENDGRID_ENABLED`, `_PUBLIC_KEY` | Settings > Mail Settings > Event Webhook, switch on Signature Verification and paste the "Verification key" |

**How a webhook finds its message.** When a provider is on, every outgoing message gets `X-Metadata-mail_log_id`
(a Symfony `MetadataHeader` carrying the row's ULID) next to the usual `X-Mail-Log-Id`, and the plugin stores the
transport's message id after sending. An event is matched by the ULID first (Postmark `Metadata`, Mailgun `user-variables`,
SendGrid custom args, Resend `tags`, SES `mail.tags` or the echoed header), then by the provider message id
(Postmark `MessageID`, Mailgun `message-id`, SES `mail.messageId`, Resend `email_id`, SendGrid `sg_message_id` / `smtp-id`).
Mail sent through the **provider's API mailer** (the Symfony Postmark / Mailgun / SendGrid / Resend / SES bridges) is matched
both ways. Over **plain SMTP** the metadata header is not turned into provider metadata, so only the message id can match,
and not every provider echoes the SMTP `Message-ID`; some relays read their own header, list it in
`webhooks.stamp_headers` (e.g. `['X-PM-Metadata-mail_log_id']` for Postmark SMTP). `webhooks.stamp_metadata=false` turns the
extra header off. Events nobody can match are acknowledged and dropped.

**Status precedence.** Webhooks arrive late, twice and out of order, so the stored status only moves forward:
`deferred < soft bounce < delivered < opened < clicked < rejected < hard bounce < complaint`. A "delivered" that arrives after a
hard bounce does not hide it; a soft bounce followed by a successful retry becomes "delivered". The mail log status
(`queued / sending / sent / failed`) is not touched. A retried webhook is stored once (unique `provider` + provider event id).

Webhooks are handled in the request, not queued: a few indexed queries, the provider retries on any non-2xx, and tracking
should not depend on a queue worker. Stored payloads are cleaned: signatures, tokens, SNS URLs are dropped and signed links
are redacted (`webhooks.store_payload=false` keeps no payload). Events go with their message when `mail-log:prune` deletes it.

### Suppression list

A **hard bounce** or a **complaint** puts the address in `mail_log_suppressions` (even when the message is not in this log:
the provider confirmed the address is dead). Recording is on by default (`suppression.record`) and visible in the
**Suppressions** resource. Nothing is blocked until you opt in:

```php
// .env: MAIL_LOG_SUPPRESSION=true
```

With `suppression.enabled`, a `MessageSending` listener removes suppressed addresses from To / Cc / Bcc, and a message left
with nobody is **not sent**: its row gets the status `suppressed`. (A partly cleaned message is logged as sent with its
original recipients.) If the lookup itself fails the mail is sent. Removing an address (single or bulk) is closed by default and has
its own permission: `Gate::define('manageMailSuppressions', ...)` or `MailLogPlugin::make()->authorizeSuppressions(fn () => ...)`.
`->suppressionResource(false)` hides the resource.

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

Fluent setters on `MailLogPlugin`: `authorize()`, `authorizeResend()`, `authorizeSuppressions()`, `suppressionResource()`, `resource()`, `previews()`, `previewPage()`, `previewLocales()`,
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
| `resend.enabled` | `true` (`MAIL_LOG_RESEND`) | master switch for the Resend action (it is still closed until the gate or `authorizeResend()` opens it) |
| `resend.mailer` | `null` (`MAIL_LOG_RESEND_MAILER`) | mailer for a resend; `null` = the default mailer |
| `resend.queue` | `false` (`MAIL_LOG_RESEND_QUEUE`) | queue the resend instead of sending it in the request (needs a worker) |
| `isolate_connection` | `true` | write the log on a separate connection (see Gotchas) |
| `webhooks.path`, `webhooks.middleware` | `mail-log/webhooks`, `[]` | the webhook route (`POST {path}/{provider}`); empty path = no route |
| `webhooks.providers.*` | all off | `enabled` + secret per provider; add your own driver class |
| `webhooks.tolerance`, `webhooks.store_payload` | `300`, `true` | replay window of signed timestamps; keep cleaned payloads |
| `webhooks.stamp_metadata`, `webhooks.stamp_headers` | `true`, `[]` | correlation headers added while a provider is on |
| `webhooks.events_table`, `suppression.table` | `mail_log_events`, `mail_log_suppressions` | table names |
| `suppression.record`, `suppression.enabled` | `true`, `false` | record bounces / complaints; actually drop suppressed recipients |

### Redaction

Applied to the bodies, the subject, the header values and the error text:

- `redaction.query_parameters`: the value of `signature`, `token`, `code`, `api_key`, ... in any URL becomes
  `[REDACTED]` (also inside HTML, where `&amp;` separates parameters).
- `redaction.path_prefixes`: `/reset-password/{token}` becomes `/reset-password/[REDACTED]` (also
  `verify-email`, `magic-link`, `invitation`, ...). Every segment after the prefix is removed, so
  `/email/verify/{id}/{hash}` becomes `/email/verify/[REDACTED]`; the query string is handled by `query_parameters`.
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
- **The recipient sees an `X-Mail-Log-Id` header** (and, while a webhook provider is on and the transport does not turn it into provider metadata, `X-Metadata-mail_log_id`). It is how `MessageSending` is tied to `MessageSent` (Symfony
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
