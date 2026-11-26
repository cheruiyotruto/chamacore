<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merry_go_round_cycles', function (Blueprint $table) {
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('merry_go_round_cycles', function (Blueprint $table) {
            $table->dropColumn(['day_of_week', 'day_of_month']);
        });
    }
};
