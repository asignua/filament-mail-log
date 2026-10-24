<?php

declare(strict_types=1);

namespace Asignua\FilamentMailLog\Models;

use Asignua\FilamentMailLog\Enums\SuppressionReason;
use Asignua\FilamentMailLog\Support\LogConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * An address that must not be mailed again (hard bounce or complaint).
 *
 * @property int $id
 * @property string $email
 * @property SuppressionReason $reason
 * @property string|null $provider
 * @property int|null $mail_log_id
 * @property string|null $detail
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class MailSuppression extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('filament-mail-log.suppression.table', 'mail_log_suppressions');
    }

    public function getConnectionName(): ?string
    {
        return LogConnection::name();
    }

    protected function casts(): array
    {
        return [
            'reason' => SuppressionReason::class,
            'mail_log_id' => 'integer',
        ];
    }
}
