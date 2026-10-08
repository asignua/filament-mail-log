# Changelog

All notable changes to `asignua/filament-mail-log` are documented here.

## 1.0.0 (unreleased)

- Log of every outgoing mail with the `queued` / `sending` / `sent` / `failed` lifecycle, correlated through the queue.
- Redaction of signed-link signatures, tokens, reset-password paths and credential labels before storing.
- Read-only Filament resource with filters, sandboxed HTML preview, plain text and headers tabs.
- Optional mail preview page for registered mailables, with a language switch.
- `mail-log:prune` with retention and a schedule that can be switched off.
- Configurable table, connection, body size cap, ignored headers, access gate.
- English and Ukrainian translations.
