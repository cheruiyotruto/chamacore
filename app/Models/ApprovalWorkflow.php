<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class ApprovalWorkflow extends Model
{
    use BelongsToGroup;

    protected $fillable = ['group_id', 'loan_product_id', 'name', 'steps', 'is_active'];

    protected function casts(): array
    {
        return ['steps' => 'array', 'is_active' => 'boolean'];
    }

    public function product()
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }
}
