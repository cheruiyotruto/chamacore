<?php

use App\Models\Group;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teller_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('open');
            $table->unsignedBigInteger('opening_float')->default(0);
            $table->unsignedBigInteger('expected_cash')->default(0);
            $table->unsignedBigInteger('counted_cash')->nullable();
            $table->bigInteger('variance')->default(0);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->text('closing_notes')->nullable();
            $table->timestamps();

            $table->index(['group_id', 'user_id', 'status']);
            $table->index(['group_id', 'status']);
        });

        Schema::create('teller_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teller_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('receipt_no', 40);
            $table->string('category', 40);
            $table->string('direction', 10);
            $table->unsignedBigInteger('amount');
            $table->string('method', 20)->default('cash');
            $table->string('reference')->nullable();
            $table->text('narration')->nullable();
            $table->nullableMorphs('source');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['group_id', 'receipt_no']);
            $table->index(['group_id', 'category']);
            $table->index(['teller_session_id', 'method']);
            $table->index(['group_id', 'created_at']);
        });

        $this->syncRoles();
    }

    /**
     * Add the teller role to every existing group and grant the teller
     * permissions to the cash-handling roles that already exist.
     */
    private function syncRoles(): void
    {
        $tellerPermissions = config('chama.role_permissions.teller', []);

        Group::withoutGlobalScopes()->orderBy('id')->each(function (Group $group) use ($tellerPermissions) {
            Role::withoutGlobalScopes()->firstOrCreate(
                ['group_id' => $group->id, 'key' => 'teller'],
                [
                    'name' => config('chama.roles.teller', 'Teller'),
                    'permissions' => $tellerPermissions,
                    'is_system' => true,
                ]
            );

            foreach (['treasurer', 'accountant'] as $key) {
                $role = Role::withoutGlobalScopes()
                    ->where('group_id', $group->id)
                    ->where('key', $key)
                    ->first();

                if (! $role) {
                    continue;
                }

                $permissions = array_values(array_unique(array_merge(
                    $role->permissions ?? [],
                    config("chama.role_permissions.{$key}", []),
                    $tellerPermissions
                )));

                $role->update(['permissions' => $permissions]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teller_entries');
        Schema::dropIfExists('teller_sessions');
    }
};
