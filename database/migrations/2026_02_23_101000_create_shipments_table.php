<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
       Schema::create('shipments', function (Blueprint $table) {
    $table->id();

    $table->string('shipment_no')->unique();        // SHIP-2024-045
    $table->unsignedBigInteger('order_id');         // no FK constraint
    $table->unsignedBigInteger('customer_id')->nullable();

    $table->string('origin', 120)->nullable();      // Shanghai, China
    $table->string('destination', 120)->nullable(); // Mumbai, India

    $table->string('carrier_name', 120)->nullable(); // Maersk Line
    $table->string('tracking_no', 80)->nullable();   // MAEU1234567

    $table->date('eta')->nullable(); // shown in UI
     $table->string('status')->default('In Transit');

    $table->text('notes')->nullable();
    $table->timestamps();

    $table->index('order_id');
    $table->index('customer_id');
    $table->index('status');
    $table->index('tracking_no');
});
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
