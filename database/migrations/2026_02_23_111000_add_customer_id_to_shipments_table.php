<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('shipments', 'customer_id')) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->unsignedBigInteger('customer_id')->nullable()->after('order_id');
                $table->index('customer_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shipments', 'customer_id')) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->dropIndex(['customer_id']);
                $table->dropColumn('customer_id');
            });
        }
    }
};
