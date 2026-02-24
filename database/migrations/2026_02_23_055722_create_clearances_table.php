<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('clearances', function (Blueprint $table) {
            $table->id();

            $table->string('clearance_no')->unique();      // CLR-2024-025
            $table->unsignedBigInteger('shipment_id');     // links shipments.id (no FK)
            $table->unsignedBigInteger('cha_id')->nullable(); // user id of CHA (optional)

            $table->string('arrival_port', 150)->nullable();
            $table->date('arrival_date')->nullable();

            $table->decimal('duty_amount', 14, 2)->nullable();
            $table->string('currency', 3)->default('USD');

            $table->string('status')->default('pending'); // pending|in_progress|cleared|released
            $table->date('clearance_date')->nullable();
            $table->date('released_date')->nullable();

            $table->timestamps();

            $table->index('shipment_id');
            $table->index('status');
            $table->index('cha_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clearances');
    }
};