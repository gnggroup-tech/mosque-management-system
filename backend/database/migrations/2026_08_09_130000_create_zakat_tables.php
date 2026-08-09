<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zakat_collections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mosque_id')->constrained()->restrictOnDelete();
            $table->foreignId('faithful_id')->nullable()->constrained('faithful')->nullOnDelete();
            $table->string('receipt_number')->unique();
            $table->string('category', 40);
            $table->decimal('assessable_amount', 18, 2)->nullable();
            $table->decimal('rate', 7, 4)->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('GNF');
            $table->string('payment_method', 30);
            $table->dateTime('collected_at');
            $table->string('status', 20)->default('pending');
            $table->boolean('is_anonymous')->default(false);
            $table->string('payer_name')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('validated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['mosque_id', 'category', 'status']);
        });

        Schema::create('zakat_beneficiaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mosque_id')->constrained()->restrictOnDelete();
            $table->foreignId('faithful_id')->nullable()->constrained('faithful')->nullOnDelete();
            $table->string('beneficiary_number')->unique();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('category', 40);
            $table->text('eligibility_reason');
            $table->string('status', 20)->default('active');
            $table->date('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['mosque_id', 'category', 'status']);
        });

        Schema::create('zakat_distributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mosque_id')->constrained()->restrictOnDelete();
            $table->foreignId('zakat_beneficiary_id')->constrained()->restrictOnDelete();
            $table->string('reference_number')->unique();
            $table->string('category', 40);
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('GNF');
            $table->string('payment_method', 30);
            $table->dateTime('distributed_at');
            $table->string('status', 20)->default('pending');
            $table->text('purpose');
            $table->string('supporting_document')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('validated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['mosque_id', 'category', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zakat_distributions');
        Schema::dropIfExists('zakat_beneficiaries');
        Schema::dropIfExists('zakat_collections');
    }
};
