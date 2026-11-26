<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use App\Support\GroupContext;

class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return app(GroupContext::class)->isStaff()
            && app(GroupContext::class)->hasPermission('documents.view');
    }

    public function view(User $user, Document $document): bool
    {
        $context = app(GroupContext::class);

        if ($context->groupId() !== $document->group_id) {
            return false;
        }

        if ($context->isMember()) {
            return $document->member_id === $context->memberId()
                && in_array($document->visibility, ['member', 'public'], true);
        }

        return $context->hasPermission('documents.view');
    }

    public function create(User $user): bool
    {
        return app(GroupContext::class)->membership() !== null;
    }

    public function verify(User $user, Document $document): bool
    {
        $context = app(GroupContext::class);

        return $context->isStaff()
            && $context->groupId() === $document->group_id
            && $context->hasPermission('documents.manage');
    }

    public function delete(User $user, Document $document): bool
    {
        $context = app(GroupContext::class);

        return $context->isStaff()
            && $context->groupId() === $document->group_id
            && $context->hasPermission('documents.manage');
    }
}
