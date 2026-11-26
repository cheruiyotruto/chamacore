<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class SubscriptionInvoice extends Model
{
    use BelongsToGroup;

    protected $fillable = [
        'group_id', 'subscription_id', 'invoice_no', 'amount', 'issue_date',
        'due_date', 'status', 'paid_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'issue_date' => 'date',
            'due_date' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function payments()
    {
        return $this->hasMany(SubscriptionPayment::class);
    }
}
