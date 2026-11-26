<?php

namespace App\Models\Concerns;

use App\Models\Group;
use App\Support\GroupContext;
use App\Support\GroupScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToGroup
{
    public static function bootBelongsToGroup(): void
    {
        static::addGlobalScope(new GroupScope);

        static::creating(function ($model) {
            if ($model->group_id === null) {
                $groupId = app(GroupContext::class)->groupId();
                if ($groupId) {
                    $model->group_id = $groupId;
                }
            }
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function scopeForGroup($query, int $groupId)
    {
        return $query->where($this->qualifyColumn('group_id'), $groupId);
    }
}
