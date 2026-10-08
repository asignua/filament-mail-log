<?php

declare(strict_types=1);

return [
    'resource' => [
        'navigation' => 'Mail log',
        'singular' => 'Message',
        'plural' => 'Mail log',
    ],
    'status' => [
        'queued' => 'Queued',
        'sending' => 'Sending',
        'sent' => 'Sent',
        'failed' => 'Failed',
    ],
    'columns' => [
        'date' => 'Date',
        'status' => 'Status',
        'recipient' => 'Recipient',
        'to' => 'To',
        'sender' => 'From',
        'subject' => 'Subject',
        'type' => 'Type',
        'mailer' => 'Mailer',
        'redacted' => 'Secrets removed',
        'message_id' => 'Message ID',
        'queued_at' => 'Queued at',
        'sent_at' => 'Sent at',
        'failed_at' => 'Failed at',
        'header' => 'Header',
        'value' => 'Value',
    ],
    'filters' => [
        'recipient' => 'Recipient contains',
        'body' => 'Text in the message',
        'from' => 'From',
        'until' => 'Until',
    ],
    'tabs' => [
        'details' => 'Details',
        'html' => 'HTML',
        'text' => 'Plain text',
        'headers' => 'Headers',
    ],
    'sections' => [
        'error' => 'Error',
        'attachments' => 'Attachments',
    ],
    'actions' => [
        'load_images' => 'Load remote images',
        'block_images' => 'Block remote images',
    ],
    'notices' => [
        'redacted' => 'Signed links, tokens and passwords were removed from the stored copy.',
        'truncated' => 'The stored body was cut at the configured size limit.',
        'no_body' => 'The body was not stored.',
        'no_text' => 'There is no plain-text part.',
    ],
    'preview' => [
        'navigation' => 'Mail preview',
        'title' => 'Mail preview',
        'message' => 'Message',
        'language' => 'Language',
        'unavailable' => 'This message can not be rendered.',
    ],
];
