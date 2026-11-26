<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Group;
use App\Support\GroupContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditService
{
    public static function record(
        string $action,
        Model $model,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $groupId = null
    ): void {
        if ($model instanceof AuditLog) {
            return;
        }

        $context = app(GroupContext::class);

        AuditLog::create([
            'group_id' => $groupId ?? $model->getAttribute('group_id') ?? $context->groupId(),
            'user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'old_values' => self::sanitize($oldValues),
            'new_values' => self::sanitize($newValues),
            'ip_address' => app()->runningInConsole() ? null : Request::ip(),
            'user_agent' => app()->runningInConsole() ? null : substr((string) Request::userAgent(), 0, 1000),
        ]);
    }

    public static function log(string $action, array $data = [], ?Group $group = null): void
    {
        AuditLog::create([
            'group_id' => $group?->id ?? app(GroupContext::class)->groupId(),
            'user_id' => Auth::id(),
            'action' => $action,
            'old_values' => null,
            'new_values' => $data ?: null,
            'ip_address' => app()->runningInConsole() ? null : Request::ip(),
            'user_agent' => app()->runningInConsole() ? null : substr((string) Request::userAgent(), 0, 1000),
        ]);
    }

    private static function sanitize(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $hidden = ['password', 'mfa_secret', 'remember_token', 'token'];

        return collect($values)
            ->except($hidden)
            ->map(fn ($value) => is_array($value) || is_object($value) ? json_encode($value) : $value)
            ->all();
    }
}
