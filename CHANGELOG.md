# Changelog

All notable changes to `asignua/filament-mail-log` are documented here.

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
