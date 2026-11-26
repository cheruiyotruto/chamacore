<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->string('platform_management_mode', 20)
                ->nullable()
                ->after('status');
            $table->timestamp('platform_managed_at')->nullable()->after('platform_management_mode');
            $table->foreignId('platform_managed_by')
                ->nullable()
                ->after('platform_managed_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->index('platform_management_mode');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropIndex(['platform_management_mode']);
            $table->dropForeign(['platform_managed_by']);
            $table->dropColumn([
                'platform_management_mode',
                'platform_managed_at',
                'platform_managed_by',
            ]);
        });
    }
};
