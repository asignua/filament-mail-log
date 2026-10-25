<?php

declare(strict_types=1);

return [
    /*
     * Master switch. When false nothing is logged (the panel pages stay available for the stored history).
     */
    'enabled' => env('MAIL_LOG_ENABLED', true),

    /*
     * Database connection and table of the log. `null` = the default connection.
     */
    'connection' => env('MAIL_LOG_CONNECTION'),

    'table' => env('MAIL_LOG_TABLE', 'mail_logs'),

    /*
     * With `connection` = null, write the log on a separate connection that clones the default one. A mail
     * sent inside a DB transaction that later rolls back then keeps its audit row. The cost: the rows are
     * committed at once, so a test that wraps everything in a transaction does not roll them back. Set to
     * false to write on the default connection (in-memory SQLite always does).
     */
    'isolate_connection' => env('MAIL_LOG_ISOLATE_CONNECTION', true),

    /*
     * Days a record is kept by `mail-log:prune`. 0 or less disables the rotation.
     */
    'retention_days' => (int) env('MAIL_LOG_RETENTION_DAYS', 90),

    /*
     * Registers `mail-log:prune` in the application schedule. Set to false to schedule it yourself.
     */
    'schedule' => [
        'enabled' => env('MAIL_LOG_SCHEDULE', true),
        'time' => '03:40',
    ],

    /*
     * A `sending` row older than this many minutes is marked failed by `mail-log:prune`: the process
     * that was sending it died (timeout, kill) and nobody will ever confirm the delivery.
     */
    'stale_after_minutes' => 30,

    /*
     * A `queued` row older than this many minutes is marked failed by `mail-log:prune` (the job was lost,
     * purged, or its retry took another route). Keep it above your longest mail delay. 0 = never.
     */
    'queued_stale_after_minutes' => 4320,

    /*
     * Name of the correlation header. Symfony clones the message inside the transport, so MessageSending
     * can only be tied to MessageSent through a header (it survives the clone). The value is a random
     * ULID, it carries nothing sensitive, but it does reach the recipient.
     */
    'header' => 'X-Mail-Log-Id',

    /*
     * Delivery tracking: statuses reported by the mail provider (delivered, bounced, complained, opened, ...)
     * through its webhook. Needs the `add_delivery_status_to_mail_logs_table` and `create_mail_log_events_table`
     * migrations. A provider is on when `enabled` is true AND its secret is filled; a webhook for a provider
     * that is off answers 404, one with a missing or wrong signature answers 403.
     */
    'webhooks' => [
        /*
         * URL prefix: the endpoint of a provider is POST {path}/{provider}, e.g. /mail-log/webhooks/postmark.
         * Empty = the route is not registered at all. The route has no CSRF and no session; add rate limits
         * with `middleware` if you want them (a busy provider sends many events per minute).
         */
        'path' => env('MAIL_LOG_WEBHOOKS_PATH', 'mail-log/webhooks'),

        /*
         * Extra route middleware. A rate limit is worth having once the endpoint is public, e.g. ['throttle:600,1']
         * (generous: a provider sends bursts of events, and a 429 makes it retry). Not set by default.
         */
        'middleware' => [],

        /*
         * An event that matches no message and happened less than this many seconds ago is answered with 503, so
         * the provider delivers it again (it often arrives before the row has its message id). Older unmatched
         * events get 200 and are dropped. 0 = always 200.
         */
        'retry_unmatched_seconds' => 60,

        /*
         * Keep the recipient's IP address, user agent, client and geolocation in the stored payload of open and
         * click events. Off: they are personal data the timeline does not need.
         */
        'keep_client_info' => false,

        'events_table' => env('MAIL_LOG_EVENTS_TABLE', 'mail_log_events'),

        /*
         * Seconds a signed timestamp may differ from now (replay protection). Providers that sign a
         * timestamp: Mailgun, Resend, SendGrid, and SNS (Amazon SES).
         */
        'tolerance' => 300,

        /*
         * Keep the (cleaned) payload of every event for the "Delivery events" timeline. Secrets, signatures
         * and signed links are removed before it is stored.
         */
        'store_payload' => true,

        /*
         * Stamp every outgoing message with `X-Metadata-mail_log_id` (a Symfony MetadataHeader: Postmark, Mailgun,
         * SendGrid, Resend and SES API transports pass it to the provider as metadata / variables / tags, and the
         * provider echoes it in its webhooks). Only when at least one provider is on. `X-Mail-Log-Id` is always set.
         */
        'stamp_metadata' => true,

        /*
         * More header names that receive the log id, for SMTP relays that read their own header,
         * e.g. Postmark SMTP: ['X-PM-Metadata-mail_log_id'].
         */
        'stamp_headers' => [],

        /*
         * A provider is `name => [driver class, options]`. Add your own by implementing
         * Asignua\FilamentMailLog\Webhooks\Contracts\WebhookDriver and listing it here.
         */
        'providers' => [
            'postmark' => [
                'driver' => Asignua\FilamentMailLog\Webhooks\Drivers\PostmarkDriver::class,
                'enabled' => env('MAIL_LOG_POSTMARK_ENABLED', false),
                // Postmark does not sign webhooks; it sends HTTP Basic credentials that you put in the webhook URL.
                'username' => env('MAIL_LOG_POSTMARK_USERNAME'),
                'password' => env('MAIL_LOG_POSTMARK_PASSWORD'),
            ],
            'mailgun' => [
                'driver' => Asignua\FilamentMailLog\Webhooks\Drivers\MailgunDriver::class,
                'enabled' => env('MAIL_LOG_MAILGUN_ENABLED', false),
                // Mailgun > Sending > Webhooks > "HTTP webhook signing key".
                'signing_key' => env('MAIL_LOG_MAILGUN_SIGNING_KEY'),
            ],
            'ses' => [
                'driver' => Asignua\FilamentMailLog\Webhooks\Drivers\SesDriver::class,
                'enabled' => env('MAIL_LOG_SES_ENABLED', false),
                // ARNs of the SNS topics that may call the endpoint (anyone can create a validly signed SNS topic,
                // so the signature alone proves nothing). Comma separated in the env.
                'topic_arns' => array_values(array_filter(array_map('trim', explode(',', (string) env('MAIL_LOG_SES_TOPIC_ARNS', ''))))),
                // Confirm the SNS subscription automatically (GET to the SubscribeURL of a signed request).
                'auto_confirm' => true,
                // SNS retries late, so its window is wider than the common one.
                'tolerance' => 3600,
            ],
            'resend' => [
                'driver' => Asignua\FilamentMailLog\Webhooks\Drivers\ResendDriver::class,
                'enabled' => env('MAIL_LOG_RESEND_WEBHOOK_ENABLED', false),
                // Resend > Webhooks > Signing secret ("whsec_...").
                'secret' => env('MAIL_LOG_RESEND_WEBHOOK_SECRET'),
            ],
            'sendgrid' => [
                'driver' => Asignua\FilamentMailLog\Webhooks\Drivers\SendGridDriver::class,
                'enabled' => env('MAIL_LOG_SENDGRID_ENABLED', false),
                // SendGrid > Settings > Mail Settings > Event Webhook > Signature Verification > Verification key.
                'public_key' => env('MAIL_LOG_SENDGRID_PUBLIC_KEY'),
            ],
        ],
    ],

    /*
     * The suppression list: addresses that hard-bounced or complained. Webhooks add them (`record`); with
     * `enabled` a MessageSending listener drops them from every outgoing message, and a message left with no
     * recipient is not sent at all (its row is `suppressed`). Needs the `create_mail_log_suppressions_table` migration.
     */
    'suppression' => [
        'enabled' => env('MAIL_LOG_SUPPRESSION', false),

        'record' => env('MAIL_LOG_SUPPRESSION_RECORD', true),

        'table' => env('MAIL_LOG_SUPPRESSIONS_TABLE', 'mail_log_suppressions'),
    ],

    'body' => [
        /*
         * Store the html/text bodies at all. Turn off when the mail is too sensitive to keep, even redacted.
         */
        'store' => true,

        /*
         * Bodies longer than this many BYTES are cut (before redaction, so the cut text is never scanned
         * by a pattern with unbounded backtracking). 0 = no limit.
         */
        'max_bytes' => 200_000,
    ],

    'headers' => [
        /*
         * Keep the message headers for the "Headers" tab (values go through the redactor).
         */
        'store' => true,

        /*
         * Headers that are never stored (lower case).
         */
        'ignore' => ['authorization', 'proxy-authorization', 'x-api-key', 'dkim-signature', 'x-smtpapi'],
    ],

    'attachments' => [
        /*
         * Store the size of each attachment. Reads the attachment once more, which costs memory for
         * large files; names and MIME types are always stored. File contents are NEVER stored.
         */
        'sizes' => true,
    ],

    'redaction' => [
        /*
         * Query parameters whose value is replaced by [REDACTED] in every stored text (bodies, subject,
         * headers, error). Case-insensitive. Extend it for your own signed links.
         */
        'query_parameters' => [
            'signature', 'sig', 'token', 'access_token', 'refresh_token', 'id_token', 'api_key', 'apikey',
            'key', 'secret', 'password', 'code', 'otp', 'hash', 'auth', 'x-amz-signature', 'x-amz-credential',
            'x-amz-security-token',
        ],

        /*
         * URL path prefixes followed by secret segments: /reset-password/{token} becomes
         * /reset-password/[REDACTED], and /email/verify/{id}/{hash} becomes /email/verify/[REDACTED] (every
         * segment after the prefix goes, up to the query string). Matched anywhere in a URL path, case-insensitive.
         */
        'path_prefixes' => [
            'reset-password', 'password/reset', 'password-reset', 'reset_password', 'forgot-password/reset',
            'email/verify', 'verify-email', 'magic-link', 'invitation', 'invite', 'unsubscribe',
        ],

        /*
         * "password: hunter2", "token = abc" inside a text. The label is kept, the value is redacted.
         */
        'credential_labels' => [
            'password', 'passcode', 'passphrase', 'secret', 'api key', 'api_key', 'token', 'pin', 'otp',
            // uk / ru
            'пароль', 'код', 'токен', 'секрет', 'пін', 'пин', 'парольна фраза',
            // de
            'passwort', 'kennwort', 'geheimnis', 'sicherheitscode', 'bestätigungscode',
            // pl
            'hasło', 'kod', 'sekret',
            // es
            'contraseña', 'código', 'clave', 'secreto',
        ],

        /*
         * Extra regular expressions (full PCRE with delimiters). The whole match is replaced by [REDACTED].
         * Add the `u` flag (`~…~iu`) for any non-ASCII text, otherwise `\b` and `/i` ignore UTF-8 letters.
         * A pattern that fails at runtime (backtrack limit) makes the stored text "[redaction failed]".
         */
        'patterns' => [],
    ],

    /*
     * Human labels for mailable / notification classes in the table: ['App\Mail\Welcome' => 'Welcome mail'].
     * A subclass of a registered class gets its parent's label. Anything else shows the short class name.
     */
    'types' => [],

    'resend' => [
        /*
         * The "Resend" action (still closed until the `resendMailLog` gate or `->authorizeResend()` allows it).
         */
        'enabled' => env('MAIL_LOG_RESEND', true),

        /*
         * Mailer used for a resend. null = the application's default mailer (not the mailer of the original,
         * which may be gone or point at a transactional service you do not want a manual resend to use).
         */
        'mailer' => env('MAIL_LOG_RESEND_MAILER'),

        /*
         * Push the resend to the queue instead of sending it in the request. Needs a running worker.
         */
        'queue' => env('MAIL_LOG_RESEND_QUEUE', false),
    ],

    'preview' => [
        /*
         * Content-Security-Policy of the sandboxed iframe that shows a message. The iframe has no
         * `allow-scripts`, so it can not run code anyway; this also blocks remote fonts, frames and
         * forms. Remote images are blocked by default: opening a stored message must not fire the
         * open-tracking pixels of the mail or leak the admin's IP. Set to null to send no policy.
         */
        'csp' => "default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:",

        /*
         * The policy used after the viewer pressed "Load remote images" on the message page.
         */
        'csp_remote_images' => "default-src 'none'; img-src data: https:; style-src 'unsafe-inline'; font-src data:",
    ],
];
