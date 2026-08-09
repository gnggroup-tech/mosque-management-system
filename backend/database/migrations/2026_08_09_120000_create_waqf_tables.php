<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waqf_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mosque_id')->constrained()->restrictOnDelete();
            $table->string('registration_number')->unique();
            $table->string('name');
            $table->string('type', 40);
            $table->text('description')->nullable();
            $table->string('address')->nullable();
            $table->decimal('estimated_value', 18, 2)->nullable();
            $table->string('currency', 3)->default('GNF');
            $table->date('dedicated_at');
            $table->string('deed_reference')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['mosque_id', 'type', 'status']);
        });

        Schema::create('waqf_revenues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('waqf_asset_id')->constrained()->restrictOnDelete();
            $table->string('receipt_number')->unique();
            $table->string('source');
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('GNF');
            $table->dateTime('received_at');
            $table->string('payment_method', 30);
            $table->string('status', 20)->default('pending');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('validated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['waqf_asset_id', 'currency', 'status']);
        });

        Schema::create('waqf_expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('waqf_asset_id')->constrained()->restrictOnDelete();
            $table->string('reference_number')->unique();
            $table->string('category', 40);
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('GNF');
            $table->dateTime('spent_at');
            $table->text('purpose');
            $table->string('supporting_document')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('validated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['waqf_asset_id', 'currency', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waqf_expenses');
        Schema::dropIfExists('waqf_revenues');
        Schema::dropIfExists('waqf_assets');
    }
};
