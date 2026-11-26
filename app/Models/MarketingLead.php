<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingLead extends Model
{
    public const STATUS_NEW = 'new';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_QUALIFIED = 'qualified';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_LOST = 'lost';

    protected $fillable = [
        'name',
        'organisation',
        'email',
        'phone',
        'group_type',
        'expected_members',
        'plan',
        'message',
        'status',
        'notes',
    ];
}
