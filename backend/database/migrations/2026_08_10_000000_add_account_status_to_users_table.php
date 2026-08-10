<?php

use App\Enums\AccountStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('status', 30)->default(AccountStatus::Active->value)->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('verification_required_at')->nullable();
            $table->timestamp('verification_exempt_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropColumn([
                'status',
                'activated_at',
                'suspended_at',
                'suspension_reason',
                'archived_at',
                'verification_required_at',
                'verification_exempt_until',
            ]);
        });
    }
};
