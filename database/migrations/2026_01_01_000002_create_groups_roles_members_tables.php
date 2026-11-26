<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('registration_no')->nullable()->unique();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('county')->nullable();
            $table->string('sub_county')->nullable();
            $table->text('address')->nullable();
            $table->string('currency', 3)->default('KES');
            $table->string('timezone')->default('Africa/Nairobi');
            $table->string('logo_path')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('subscription_status', 20)->default('trialing');
            $table->timestamp('trial_ends_at')->nullable();
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1);
            $table->json('settings')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('subscription_status');
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 50);
            $table->string('name');
            $table->json('permissions')->nullable();
            $table->unsignedBigInteger('approval_limit')->nullable();
            $table->boolean('is_system')->default(true);
            $table->timestamps();

            $table->unique(['group_id', 'key']);
        });

        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->string('member_no', 40);
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('national_id', 40)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('gender', 20)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->text('address')->nullable();
            $table->string('occupation')->nullable();
            $table->string('employer')->nullable();
            $table->text('employer_address')->nullable();
            $table->string('membership_category', 40)->default('ordinary');
            $table->string('status', 30)->default('applied');
            $table->string('kyc_status', 30)->default('pending');
            $table->timestamp('joined_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('id_document_path')->nullable();
            $table->unsignedBigInteger('membership_fee_paid')->default(0);
            $table->json('metadata')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['group_id', 'member_no']);
            $table->unique(['group_id', 'user_id']);
            $table->index(['group_id', 'status']);
            $table->index(['group_id', 'national_id']);
            $table->index(['group_id', 'phone']);
        });

        Schema::create('group_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['group_id', 'key']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 100);
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['group_id', 'created_at']);
            $table->index(['auditable_type', 'auditable_id']);
        });

        Schema::create('login_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('successful')->default(false);
            $table->string('failure_reason')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index('ip_address');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_activities');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('group_settings');
        Schema::dropIfExists('members');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('groups');
    }
};
