<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subsidies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mosque_id')->constrained()->restrictOnDelete();
            $table->string('reference_number')->unique();
            $table->string('source');
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('GNF');
            $table->dateTime('received_at');
            $table->string('purpose')->nullable();
            $table->string('supporting_document')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('validated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['mosque_id', 'currency', 'status']);
        });

        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mosque_id')->constrained()->restrictOnDelete();
            $table->string('reference_number')->unique();
            $table->string('category', 40);
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('GNF');
            $table->dateTime('spent_at');
            $table->text('purpose');
            $table->string('supplier')->nullable();
            $table->string('invoice_number')->nullable();
            $table->string('supporting_document');
            $table->string('status', 20)->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('validated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['mosque_id', 'currency', 'status', 'spent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('subsidies');
    }
};