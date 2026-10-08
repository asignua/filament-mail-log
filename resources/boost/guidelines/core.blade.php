## Filament Mail Log (asignua/filament-mail-log)

- Register the plugin on the panel: `->plugin(Asignua\FilamentMailLog\MailLogPlugin::make())`, publish and run the migration (`php artisan vendor:publish --tag=filament-mail-log-migrations && php artisan migrate`).
- Logging needs no registration: the listeners are wired by the service provider and record every mail, queued or not.
- Access is closed by default. Define the `viewMailLog` gate ability or call `->authorize(fn (): bool => ...)` on the plugin.
- Secrets are redacted BEFORE storing (`config('filament-mail-log.redaction')`): add query parameters, path prefixes or regexes there for your own signed links. Never read the body of a mail from the log to rebuild a link.
- Message previews for designers: `->previews(['Welcome' => fn () => new WelcomeMail($user)])`.
- Never `return false` from a `MessageSending` listener of your own on behalf of the log, and never edit `MailLog` rows: the log is an audit trail.
- Retention: `MAIL_LOG_RETENTION_DAYS` (default 90) and `mail-log:prune`; it is scheduled daily unless `MAIL_LOG_SCHEDULE=false`.
