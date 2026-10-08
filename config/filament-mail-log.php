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
     * Name of the correlation header. Symfony clones the message inside the transport, so MessageSending
     * can only be tied to MessageSent through a header (it survives the clone). The value is a random
     * ULID, it carries nothing sensitive, but it does reach the recipient.
     */
    'header' => 'X-Mail-Log-Id',

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
         * URL path prefixes followed by a secret segment: /reset-password/{token} becomes
         * /reset-password/[REDACTED]. Matched anywhere in a URL path, case-insensitive.
         */
        'path_prefixes' => [
            'reset-password', 'password/reset', 'password-reset', 'reset_password', 'forgot-password/reset',
            'email/verify', 'verify-email', 'magic-link', 'invitation', 'invite', 'unsubscribe',
        ],

        /*
         * "password: hunter2", "token = abc" inside a text. The label is kept, the value is redacted.
         */
        'credential_labels' => ['password', 'passcode', 'passphrase', 'secret', 'api key', 'api_key', 'token', 'pin', 'otp'],

        /*
         * Extra regular expressions (full PCRE with delimiters). The whole match is replaced by [REDACTED].
         * A pattern that fails at runtime (backtrack limit) makes the stored text "[redaction failed]".
         */
        'patterns' => [],
    ],

    /*
     * Human labels for mailable / notification classes in the table: ['App\Mail\Welcome' => 'Welcome mail'].
     * A subclass of a registered class gets its parent's label. Anything else shows the short class name.
     */
    'types' => [],

    'preview' => [
        /*
         * Content-Security-Policy of the sandboxed iframe that shows a message. The iframe has no
         * `allow-scripts`, so it can not run code anyway; this also blocks remote fonts, frames and
         * forms. Images over https stay allowed, a mail without its logo is hard to read.
         * Set to null to send no policy.
         */
        'csp' => "default-src 'none'; img-src data: https:; style-src 'unsafe-inline'; font-src data:",
    ],
];
