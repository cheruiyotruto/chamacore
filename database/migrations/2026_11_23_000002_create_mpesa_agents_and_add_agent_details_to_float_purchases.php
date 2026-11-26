<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A registered M-Pesa agent can own one or more stores. Float is
         * delivered to the store number, not to a personal M-Pesa phone.
         */
        Schema::create('mpesa_agents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('agent_number', 30);
            $table->string('store_number', 30);
            $table->string('phone', 30);
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['group_id', 'status']);
            $table->unique(['group_id', 'store_number']);
        });

        Schema::table('float_purchases', function (Blueprint $table) {
            $table->foreignId('agent_id')->nullable()->after('phone')->constrained('mpesa_agents')->nullOnDelete();
            $table->string('agent_number', 30)->nullable()->after('agent_id');
            $table->string('store_number', 30)->nullable()->after('agent_number');
            $table->string('agent_name', 120)->nullable()->after('store_number');

            $table->index(['group_id', 'store_number']);
        });
    }

    public function down(): void
    {
        Schema::table('float_purchases', function (Blueprint $table) {
            $table->dropIndex(['group_id', 'store_number']);
            $table->dropColumn(['agent_id', 'agent_number', 'store_number', 'agent_name']);
        });

        Schema::dropIfExists('mpesa_agents');
    }
};
