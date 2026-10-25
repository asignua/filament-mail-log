# Changelog

All notable changes to `asignua/filament-mail-log` are documented here.

## Unreleased

- New: **delivery tracking through provider webhooks** (Postmark, Mailgun, Amazon SES via SNS, Resend, SendGrid): delivered, bounced (hard / soft), complained, opened, clicked, rejected and deferred events are stored in `mail_log_events`, the latest meaningful one is kept on the message as `delivery_status` (with a precedence that makes late and out-of-order webhooks harmless). Signature checks per provider (403 on a bad one, replay window, SNS certificate and topic checks, automatic SNS subscription confirmation), idempotent on the provider event id, processed in the request. Off until a provider is enabled with its secret. Add your own provider by implementing `Webhooks\Contracts\WebhookDriver`.
- New: outgoing mail is stamped with `X-Metadata-mail_log_id` (a Symfony `MetadataHeader`) while a provider is on, so events can be matched without the transport message id; `webhooks.stamp_headers` adds more header names for SMTP relays.
- New: **suppression list** (`mail_log_suppressions`): hard bounces and complaints are recorded; with `suppression.enabled` suppressed addresses are dropped from outgoing mail and a message with no recipient left is not sent (status `suppressed`). "Suppressions" resource; removing is closed behind the `manageMailSuppressions` gate / `authorizeSuppressions()`.
- New: delivery status badge column and filter in the table, a "Delivery events" timeline on the message page, English and Ukrainian translations.
- **Needs three new migrations** (`add_delivery_status_to_mail_logs_table`, `create_mail_log_events_table`, `create_mail_log_suppressions_table`); publish and run them. Until then logging works as before and the new UI is hidden.
- Hardening after review: SNS certificate host pinned to real regional endpoints and the certificate itself verified; Mailgun signature bound to its first body; only real bounces / complaints suppress (Mailgun reasons, Postmark `SpamNotification`, SES `not-spam`); bad keys and certificates give 403 instead of 500; out-of-range event dates fall back to now; fresh unmatched events get a retryable 503; client IP / user agent / geolocation are no longer stored; refused requests log at debug.
- Config: `webhooks.*`, `suppression.*`.

## 1.1.0 - 2026-10-25

- New: **Resend** action (message page and row menu): mails the stored copy again to the same or other recipients, with optional Cc/Bcc, validated addresses and a confirmation modal. Closed by default behind its own permission (`resendMailLog` gate or `MailLogPlugin::authorizeResend()`), separate from viewing the log.
- The modal warns when secrets were removed from the stored copy (signed links will be broken) or the body was cut, and says that attachments are not resent (only their names are stored).
- The resend is logged as a new row linked to the original (`resent_from_id`); the message page shows "Resent from #id" / "Resent N times". **Needs the new migration** `add_resent_from_id_to_mail_logs_table` (publish and run it; until then logging works as before and the action is hidden).
- Config: `resend.enabled`, `resend.mailer`, `resend.queue`.
- English and Ukrainian translations.

## 1.0.1 - 2026-10-12

- Fix: the HTML preview iframe is full width on a stock Filament panel (it used Tailwind classes that Filament's CSS does not contain, so it was 300px wide). Inline styles, light and dark mode.
- Fix: redaction of `redaction.path_prefixes` now removes EVERY segment after the prefix. Laravel's verification link `/email/verify/{id}/{hash}` was stored as `/email/verify/[REDACTED]/{hash}`: the id was hidden and the secret hash stayed visible. The e-mail query of a reset link (`?email=…`) is still kept: it is personal data, not a secret (use `redaction.patterns` to drop it).
- Docs: screenshots, cover and a workbench demo.

## 1.0.0 - 2026-10-09

- Fix: redaction regexes are UTF-8 safe (`u` flag, Unicode-aware boundary), so `Пароль: …` is redacted; default credential labels now include uk/ru, de, pl, es; custom `patterns` must carry `u` for non-ASCII text.

- Log of every outgoing mail with the `queued` / `sending` / `sent` / `failed` lifecycle, correlated through the queue.
- Redaction of signed-link signatures, tokens, reset-password paths and credential labels before storing.
- Read-only Filament resource with filters, sandboxed HTML preview, plain text and headers tabs.
- Optional mail preview page for registered mailables, with a language switch.
- `mail-log:prune` with retention and a schedule that can be switched off.
- Configurable table, connection, body size cap, ignored headers, access gate.
- English and Ukrainian translations.
