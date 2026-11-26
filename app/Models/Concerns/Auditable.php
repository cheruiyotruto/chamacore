<?php

namespace App\Models\Concerns;

use App\Services\AuditService;

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($model) => AuditService::record('created', $model, null, $model->getAttributes()));

        static::updated(fn ($model) => AuditService::record('updated', $model, $model->getOriginal(), $model->getChanges()));

        static::deleted(fn ($model) => AuditService::record('deleted', $model, $model->getOriginal(), null));
    }
}
