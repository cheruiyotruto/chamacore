<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_invitations', function (Blueprint $table) {
            $table->boolean('require_biometric')->default(false)->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('member_invitations', function (Blueprint $table) {
            $table->dropColumn('require_biometric');
        });
    }
};
