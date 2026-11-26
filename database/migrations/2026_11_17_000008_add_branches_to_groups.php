<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->foreignId('parent_group_id')
                ->nullable()
                ->after('id')
                ->constrained('groups')
                ->nullOnDelete();
            $table->boolean('is_branch')->default(false)->after('name');
            $table->string('branch_code', 40)->nullable()->after('slug');

            $table->index(['parent_group_id', 'is_branch']);
            $table->unique(['parent_group_id', 'branch_code'], 'groups_parent_branch_code_unique');
        });

        DB::table('groups')->update(['is_branch' => false]);
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropUnique('groups_parent_branch_code_unique');
            $table->dropIndex(['parent_group_id', 'is_branch']);
            $table->dropForeign(['parent_group_id']);
            $table->dropColumn(['parent_group_id', 'is_branch', 'branch_code']);
        });
    }
};
