<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use BelongsToGroup;

    protected $fillable = ['group_id', 'key', 'name', 'permissions', 'approval_limit', 'is_system'];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'approval_limit' => 'integer',
            'is_system' => 'boolean',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function hasPermission(string $permission): bool
    {
        $permissions = $this->permissions ?? [];

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }
}
