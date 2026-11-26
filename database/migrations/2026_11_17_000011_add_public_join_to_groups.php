<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->boolean('public_join_enabled')->default(false)->after('description');
            $table->text('public_join_summary')->nullable()->after('public_join_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn(['public_join_enabled', 'public_join_summary']);
        });
    }
};
