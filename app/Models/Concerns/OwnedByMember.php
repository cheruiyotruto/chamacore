<?php

namespace App\Models\Concerns;

use App\Support\MemberScope;

trait OwnedByMember
{
    public static function bootOwnedByMember(): void
    {
        static::addGlobalScope(new MemberScope);
    }

    public function memberScopeColumn(): string
    {
        return 'member_id';
    }
}
