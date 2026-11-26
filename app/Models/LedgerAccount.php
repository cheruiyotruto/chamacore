<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class LedgerAccount extends Model
{
    use BelongsToGroup;

    public const TYPE_ASSET = 'asset';
    public const TYPE_LIABILITY = 'liability';
    public const TYPE_EQUITY = 'equity';
    public const TYPE_INCOME = 'income';
    public const TYPE_EXPENSE = 'expense';

    protected $fillable = [
        'group_id', 'parent_id', 'code', 'name', 'type', 'normal_balance',
        'is_system', 'is_active', 'description',
    ];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'is_active' => 'boolean'];
    }

    public function parent()
    {
        return $this->belongsTo(LedgerAccount::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(LedgerAccount::class, 'parent_id');
    }

    public function lines()
    {
        return $this->hasMany(LedgerLine::class);
    }

    public function isDebitNormal(): bool
    {
        return $this->normal_balance === 'debit';
    }
}
