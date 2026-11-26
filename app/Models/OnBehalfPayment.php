<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class OnBehalfPayment extends Model
{
    use BelongsToGroup;

    protected $fillable = [
        'group_id', 'payer_member_id', 'member_id', 'type', 'amount', 'method',
        'status', 'reference', 'payable_type', 'payable_id', 'payment_intent_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    public function payer()
    {
        return $this->belongsTo(Member::class, 'payer_member_id');
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function payable()
    {
        return $this->morphTo();
    }

    public function paymentIntent()
    {
        return $this->belongsTo(PaymentIntent::class);
    }
}
