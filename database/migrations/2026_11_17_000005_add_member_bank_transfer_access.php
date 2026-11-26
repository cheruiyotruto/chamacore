<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->boolean('can_bank_transfer')->default(false)->after('status');
        });

        Role::withoutGlobalScopes()
            ->whereIn('key', ['treasurer', 'accountant'])
            ->get()
            ->each(function (Role $role) {
                $permissions = array_values(array_unique(array_merge(
                    $role->permissions ?? [],
                    ['payouts.view', 'payouts.manage']
                )));
                $role->update(['permissions' => $permissions]);
            });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('can_bank_transfer');
        });
    }
};
