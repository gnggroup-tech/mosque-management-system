<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcement_receipts', function (Blueprint $table): void {
            $table->timestamp('available_at')->nullable()->after('delivered_at');
            $table->index(['user_id', 'read_at', 'available_at']);
        });

        DB::table('announcement_receipts')
            ->whereNull('available_at')
            ->update(['available_at' => DB::raw('delivered_at')]);
    }

    public function down(): void
    {
        Schema::table('announcement_receipts', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'read_at', 'available_at']);
            $table->dropColumn('available_at');
        });
    }
};
