<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Asignua\FilamentMailLog\Concerns\HasMailLogs;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $number
 * @property string $customer_name
 * @property string $customer_email
 * @property int $total_cents
 * @property string $status
 */
class Order extends Model
{
    use HasMailLogs;

    protected $guarded = [];
}
