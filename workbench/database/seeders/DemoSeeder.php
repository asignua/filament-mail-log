<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Asignua\FilamentMailLog\Models\MailLog;
use Asignua\FilamentMailLog\Support\MailRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Workbench\App\Models\User;

/**
 * Screenshot data (`vendor/bin/testbench db:seed --class='Workbench\Database\Seeders\DemoSeeder'` after
 * `workbench:build`). Log in as emma@example.com / password. The rows are written directly, but every body, subject
 * and header goes through the plugin's own redactor, exactly as the listener does.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->firstOrCreate(['email' => 'emma@example.com'], ['name' => 'Emma Carter', 'password' => 'password']);

        $now = CarbonImmutable::now()->startOfMinute();

        $verifyUrl = 'https://portal.acme-supply.example/verify-email/41/9c1f0d7ab2e84c55?expires=1791800000&signature=3f6b1c0e9a7d4b2f8e5a1c3d7b9f0a2e4c6d8b1a3f5e7c9d0b2a4c6e8f1a3b5d';
        $resetUrl = 'https://portal.acme-supply.example/reset-password/Zk3p9QwL2xV7mT4nB8cR1sH6dJ5fY0gA?email=olena%40kyivpack.example';

        $rows = [
            [
                'type' => 'App\\Mail\\VerifyEmail', 'to' => 'olena.petrenko@kyivpack.example', 'subject' => 'Confirm your email address',
                'status' => 'sent', 'at' => $now->subMinutes(4),
                'html' => $this->layout('Confirm your email', "<p>Hi Olena,</p><p>Please confirm that this is your email address to activate your Acme Supply account.</p><p><a class=\"btn\" href=\"{$verifyUrl}\">Verify email address</a></p><p class=\"muted\">If the button does not work, paste this link into your browser:<br><a href=\"{$verifyUrl}\">{$verifyUrl}</a></p>"),
                'text' => "Hi Olena,\n\nPlease confirm your email address:\n{$verifyUrl}\n\nIf you did not create an account, no further action is required.\n\nAcme Supply",
            ],
            [
                'type' => 'App\\Mail\\InvoiceMail', 'to' => 'accounts@warszawa-logistics.example', 'subject' => 'Invoice INV-2026-0412 from Acme Supply',
                'status' => 'sent', 'at' => $now->subMinutes(32), 'attachments' => [['name' => 'INV-2026-0412.pdf', 'mime' => 'application/pdf', 'size' => 48210]],
                'html' => $this->layout('Invoice INV-2026-0412', '<p>Dear Warszawa Logistics team,</p><p>Your invoice for September is attached. The amount due is <strong>€4,280.00</strong> by <strong>25 October 2026</strong>.</p><table><tr><th>Item</th><th>Qty</th><th>Amount</th></tr><tr><td>Euro pallet EPAL</td><td>120</td><td>€1,740.00</td></tr><tr><td>Stretch film 500 mm</td><td>240</td><td>€2,160.00</td></tr><tr><td>Delivery to Gdansk</td><td>1</td><td>€380.00</td></tr></table><p>Thank you for your business.</p>'),
                'text' => "Dear Warszawa Logistics team,\n\nYour invoice INV-2026-0412 is attached. Amount due: EUR 4,280.00 by 25 October 2026.\n\nAcme Supply",
            ],
            [
                'type' => 'App\\Mail\\ResetPassword', 'to' => 'olena.petrenko@kyivpack.example', 'subject' => 'Reset your password',
                'status' => 'sent', 'at' => $now->subHours(2),
                'html' => $this->layout('Reset your password', "<p>You are receiving this email because we received a password reset request for your account.</p><p><a class=\"btn\" href=\"{$resetUrl}\">Reset password</a></p><p class=\"muted\">This link expires in 60 minutes.</p>"),
                'text' => "Reset your password: {$resetUrl}\nThis link expires in 60 minutes.\n\nPassword: Spring-2026-Reset",
            ],
            [
                'type' => 'App\\Notifications\\ShipmentDispatched', 'to' => 'dock@poznan-labels.example', 'cc' => ['logistics@poznan-labels.example'], 'subject' => 'Your order SO-88213 has left the warehouse',
                'status' => 'queued', 'at' => $now->subMinutes(1),
                'html' => $this->layout('Shipment on its way', '<p>Order <strong>SO-88213</strong> (Thermal label roll 100x150, 50 rolls) left our warehouse today. Expected delivery: 14 October.</p>'),
                'text' => 'Order SO-88213 left our warehouse today. Expected delivery: 14 October.',
            ],
            [
                'type' => 'App\\Mail\\QuoteMail', 'to' => 'purchasing@praha-plastics.example', 'subject' => 'Quote Q-1077: stretch film, 2 pallets',
                'status' => 'failed', 'at' => $now->subHours(5), 'error' => 'Expected response code "250" but got code "550", with message "550 5.1.1 <purchasing@praha-plastics.example>: Recipient address rejected: User unknown in virtual mailbox table".',
                'html' => $this->layout('Quote Q-1077', '<p>Please find our quote for 2 pallets of stretch film attached to this message. Valid for 14 days.</p>'),
                'text' => 'Quote Q-1077: 2 pallets of stretch film. Valid for 14 days.',
            ],
            [
                'type' => 'App\\Mail\\WelcomeMail', 'to' => 'k.nowak@rhein-maschinenbau.example', 'subject' => 'Welcome to Acme Supply',
                'status' => 'sent', 'at' => $now->subHours(9),
                'html' => $this->layout('Welcome to Acme Supply', '<p>Hi Klaus, your trade account is ready. Order packaging, pallets and labels in a few clicks.</p>'),
                'text' => 'Hi Klaus, your trade account is ready.',
            ],
            [
                'type' => 'App\\Mail\\InvoiceMail', 'to' => 'finance@iberia-fresh.example', 'subject' => 'Invoice INV-2026-0409 from Acme Supply',
                'status' => 'sent', 'at' => $now->subDay(), 'attachments' => [['name' => 'INV-2026-0409.pdf', 'mime' => 'application/pdf', 'size' => 51877]],
                'html' => $this->layout('Invoice INV-2026-0409', '<p>Your invoice for seasonal produce crates is attached. Amount due: <strong>€1,915.50</strong>.</p>'),
                'text' => 'Your invoice INV-2026-0409 is attached. Amount due: EUR 1,915.50.',
            ],
            [
                'type' => 'App\\Mail\\OrderConfirmation', 'to' => 'orders@thames-cargo.example', 'subject' => 'Order SO-88190 confirmed',
                'status' => 'failed', 'at' => $now->subDays(2), 'error' => 'Connection could not be established with host "smtp.mailrelay.example:587": stream_socket_client(): Unable to connect (Connection timed out).',
                'html' => $this->layout('Order confirmed', '<p>Thank you, order SO-88190 is confirmed.</p>'),
                'text' => 'Thank you, order SO-88190 is confirmed.',
            ],
            [
                'type' => 'App\\Mail\\VerifyEmail', 'to' => 'm.horak@dnipro-pallets.example', 'subject' => 'Confirm your email address',
                'status' => 'sent', 'at' => $now->subDays(3),
                'html' => $this->layout('Confirm your email', '<p>Please confirm your email address.</p><p><a class="btn" href="'.str_replace('41/9c1f0d7ab2e84c55', '37/2b7e5d1c9a604f83', $verifyUrl).'">Verify email address</a></p>'),
                'text' => 'Please confirm your email address.',
            ],
            [
                'type' => 'App\\Notifications\\ShipmentDispatched', 'to' => 'dock@kyivpack.example', 'subject' => 'Your order SO-88104 has left the warehouse',
                'status' => 'sent', 'at' => $now->subDays(4),
                'html' => $this->layout('Shipment on its way', '<p>Order <strong>SO-88104</strong> left our warehouse today.</p>'),
                'text' => 'Order SO-88104 left our warehouse today.',
            ],
        ];

        // The list sorts by id, so insert the oldest first.
        usort($rows, fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        foreach ($rows as $i => $row) {
            $at = $row['at'];
            $status = $row['status'];
            $to = $row['to'];

            MailLog::query()->create([
                'ulid' => (string) Str::ulid(),
                'message_id' => $status === 'sent' ? sprintf('%s.%d@acme-supply.example', Str::lower(Str::random(20)), $i) : null,
                'mailer' => 'smtp',
                'type' => $row['type'],
                'subject' => MailRedactor::clean($row['subject']),
                'sender' => 'Acme Supply <hello@acme-supply.example>',
                'recipient' => $to,
                'recipients' => ['to' => [$to], 'cc' => $row['cc'] ?? [], 'bcc' => []],
                'status' => $status,
                'error' => isset($row['error']) ? MailRedactor::clean($row['error']) : null,
                'html_body' => MailRedactor::clean($row['html']),
                'text_body' => MailRedactor::clean($row['text']),
                'redacted' => str_contains($row['html'].$row['text'], 'signature=') || str_contains($row['html'], 'reset-password/') || str_contains($row['text'], 'Password:'),
                'truncated' => false,
                'attachments' => $row['attachments'] ?? null,
                'headers' => [
                    'From' => ['Acme Supply <hello@acme-supply.example>'],
                    'To' => [$to],
                    'Subject' => [MailRedactor::clean($row['subject'])],
                    'Message-ID' => ['<'.Str::lower(Str::random(20)).'@acme-supply.example>'],
                    'X-Mail-Log-Id' => [(string) Str::ulid()],
                    'MIME-Version' => ['1.0'],
                ],
                'queued_at' => $status === 'queued' ? $at : $at->subSeconds(2),
                'sent_at' => $status === 'sent' ? $at : null,
                'failed_at' => $status === 'failed' ? $at : null,
                'created_at' => $at->subSeconds(2),
                'updated_at' => $at,
            ]);
        }
    }

    private function layout(string $heading, string $body): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><style>body{margin:0;background:#f4f4f5;font-family:Helvetica,Arial,sans-serif;color:#18181b}.card{max-width:560px;margin:24px auto;background:#fff;border-radius:12px;padding:32px;border:1px solid #e4e4e7}h1{font-size:22px;margin:0 0 16px}.brand{font-weight:700;color:#b45309;margin-bottom:20px}.btn{display:inline-block;background:#f59e0b;color:#451a03;text-decoration:none;font-weight:600;padding:10px 20px;border-radius:8px}.muted{color:#71717a;font-size:13px}table{width:100%;border-collapse:collapse;margin:16px 0}th,td{text-align:left;padding:8px;border-bottom:1px solid #e4e4e7;font-size:14px}</style></head><body><div class="card"><div class="brand">Acme Supply</div><h1>'.$heading.'</h1>'.$body.'</div></body></html>';
    }
}
