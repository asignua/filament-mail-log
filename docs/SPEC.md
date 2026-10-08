# Filament Mail Log — spec

Free (MIT) log of outgoing mail for Filament 5. Ported from the eEgnith CMS mail log and stripped of its domain
specifics (roles, `ee.` translation keys, settings, user lookup).

## Scope

- Log **every** message of the application: queued mailables and notifications, sent, failed.
- Follow a message through its life: `queued` -> `sending` -> `sent` / `failed`.
- Store a **redacted** copy: no live signed link, reset token or password ever reaches the database.
- Read-only Filament resource: list (filters: status, type, recipient, period, text in body), view page with
  Details / HTML / Plain text / Headers tabs. HTML is shown in a sandboxed iframe.
- Optional "Mail preview" page that renders registered mailables in several languages (designers).
- `mail-log:prune` (retention) and its schedule (opt-out).

## Public API

| Piece | Where |
| --- | --- |
| Plugin | `MailLogPlugin::make()` with `authorize()`, `resource()`, `previews()`, `previewPage()`, `previewLocales()`, `navigationGroup/Sort/Icon()` |
| Config | `config/filament-mail-log.php`: `enabled`, `connection`, `table`, `retention_days`, `schedule`, `stale_after_minutes`, `header`, `body`, `headers`, `attachments`, `redaction`, `types`, `preview` |
| Gate | ability `viewMailLog` (default access check) |
| Model | `Models\MailLog` (table and connection come from the config), `Enums\MailStatus` |
| Command | `mail-log:prune {--days=}` |
| Migration | publish tag `filament-mail-log-migrations` |
| Redactor | `Support\MailRedactor::apply()` / `::clean()` |

## How the correlation works

1. `JobQueued` (SendQueuedMailable / SendQueuedNotifications on a non-sync connection) creates a `queued` row
   carrying the job id.
2. `MessageSending` creates the row (or continues the `queued` row of the current job, found through the job
   id set by `JobProcessing`), stores the redacted copy and adds the `X-Mail-Log-Id` header. Symfony clones the
   message inside the transport, so the header is the only way to tie `MessageSent` back to the row.
3. `MessageSent` marks it `sent` and stores the message id.
4. `JobExceptionOccurred` fails the rows still `sending` in that worker; `JobFailed` fails a `queued` row whose job
   died before reaching the mailer.
5. Outside a queue job, a transport exception produces no event: the end of the request/command (`terminating`)
   fails whatever is still `sending`. `mail-log:prune` additionally fails `sending` rows older than
   `stale_after_minutes` (killed worker).

The listeners never throw and never return `false` (that would cancel the mail).

## Extension points

- `redaction.query_parameters`, `.path_prefixes`, `.credential_labels`, `.patterns` (regex).
- `types` registry of human labels for mailable / notification classes (a subclass inherits its parent's label).
- `authorize()` callback or the `viewMailLog` gate.
- Replace the schedule: `schedule.enabled=false` and schedule `mail-log:prune` yourself.

## Non-goals (v1)

- No delivery tracking (opens, clicks, bounces): that needs ESP webhooks.
- No resend / edit / delete from the panel: the log is an audit trail.
- No attachment contents, ever. Names, MIME types and (optionally) sizes only.
- No user relation: the recipient address is the identity; a host can join on it.
- Notification routes are only known for the first notifiable of a queued notification.
