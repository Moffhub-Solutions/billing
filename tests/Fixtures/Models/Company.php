<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Moffhub\Billing\Contracts\BillableInterface;
use Moffhub\Billing\Traits\Billable;

class Company extends Model implements BillableInterface
{
    use Billable;

    protected $guarded = [];

    protected $table = 'companies';
}
