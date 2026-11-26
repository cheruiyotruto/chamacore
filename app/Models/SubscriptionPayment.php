<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPayment extends Model
{
    use BelongsToGroup;

    protected $fillable = [
        'group_id', 'subscription_invoice_id', 'amount', 'method',
        'reference', 'paid_at', 'recorded_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_at' => 'datetime'];
    }

    public function invoice()
    {
        return $this->belongsTo(SubscriptionInvoice::class, 'subscription_invoice_id');
    }
}
