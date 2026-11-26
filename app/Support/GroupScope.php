<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class GroupScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(GroupContext::class);

        if ($context->isBypassed()) {
            return;
        }

        if ($groupId = $context->groupId()) {
            $builder->where($model->qualifyColumn('group_id'), $groupId);

            return;
        }

        // An authenticated user with no valid group context must not be able
        // to read tenant data. Console operations must opt in explicitly.
        if (auth()->check() || ! app()->runningInConsole()) {
            $builder->whereRaw('1 = 0');
        }
    }
}
