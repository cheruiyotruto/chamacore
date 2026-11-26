<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class LoanApproval extends Model
{
    use BelongsToGroup;

    protected $fillable = [
        'group_id', 'loan_id', 'step_index', 'role_key', 'actor_id', 'action',
        'comments', 'acted_at', 'metadata',
    ];

    protected function casts(): array
    {
        return ['acted_at' => 'datetime', 'metadata' => 'array', 'step_index' => 'integer'];
    }

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
