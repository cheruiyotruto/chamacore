<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guarantors', function (Blueprint $table) {
            $table->unsignedBigInteger('principal_amount')->nullable()->after('guaranteed_amount');
            $table->unsignedBigInteger('full_obligation_amount')->nullable()->after('principal_amount');
            $table->string('coverage_basis', 30)->nullable()->after('full_obligation_amount');
        });
    }

    public function down(): void
    {
        Schema::table('guarantors', function (Blueprint $table) {
            $table->dropColumn(['principal_amount', 'full_obligation_amount', 'coverage_basis']);
        });
    }
};
