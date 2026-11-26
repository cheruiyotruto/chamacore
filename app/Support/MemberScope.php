<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class MemberScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(GroupContext::class);

        if ($context->isBypassed() || ! $context->isMember()) {
            return;
        }

        $memberId = $context->memberId();

        if (! $memberId) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $column = method_exists($model, 'memberScopeColumn') ? $model->memberScopeColumn() : 'member_id';
        $builder->where($model->qualifyColumn($column), $memberId);
    }
}
