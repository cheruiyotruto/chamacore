<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\Member;
use App\Models\User;
use App\Support\GroupContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

abstract class Controller
{
    use AuthorizesRequests;
    use ValidatesRequests;

    protected function context(): GroupContext
    {
        return app(GroupContext::class);
    }

    protected function group(): Group
    {
        $group = $this->context()->group();

        abort_if($group === null, 403, 'No active group context.');

        return $group;
    }

    /**
     * The authenticated user's own membership in the active group.
     */
    protected function membership(): Member
    {
        $membership = $this->context()->membership();

        abort_if($membership === null, 403, 'No active membership in this group.');

        return $membership;
    }

    protected function actor(): User
    {
        /** @var User $user */
        $user = request()->user();

        abort_if($user === null, 401);

        return $user;
    }

    protected function requireStaff(): void
    {
        abort_unless($this->context()->isStaff(), 403, 'Staff access is required.');
    }

    protected function requirePermission(string $permission): void
    {
        abort_unless(
            $this->context()->hasPermission($permission),
            403,
            'You are not authorised for this action.'
        );
    }

    /**
     * CSV download helper used by list and report endpoints.
     *
     * @param  array<int, string>  $headings
     * @param  iterable<int, array<int, scalar|null>>  $rows
     */
    protected function csv(string $filename, array $headings, iterable $rows)
    {
        return response()->streamDownload(function () use ($headings, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headings);

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
