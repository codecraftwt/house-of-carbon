<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            $table->string('order_no')->unique();

            // IDs without foreign key constraints
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('quotation_id')->nullable();

            $table->string('status')->default('draft');
            // draft|confirmed|in_transit|arrived|clearance|delivered|cancelled
            $table->json('status_timeline')->nullable();

           
            $table->string('origin_country', 80)->nullable();
            $table->string('destination_port', 120)->nullable();

            $table->decimal('invoice_value', 14, 2)->nullable();
            $table->string('currency', 3)->default('USD');

            $table->date('expected_arrival_date')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            // Indexes (important since no FK constraint)
            $table->index(['customer_id', 'status']);
            $table->index('supplier_id');
            $table->index('quotation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
