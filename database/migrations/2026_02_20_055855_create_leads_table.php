<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();

            $table->string('company');
            $table->string('contact');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            $table->decimal('value', 12, 2)->nullable(); // expected value
            $table->date('added_date')->nullable();      // UI has addedDate
            $table->date('last_contact')->nullable();    // UI has lastContact

            $table->enum('status', ['new', 'contacted', 'qualified', 'converted'])
                  ->default('new');

            $table->softDeletes();
            $table->timestamps();

            // Optional: who created it
            // $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['status']);
            $table->index(['company', 'contact', 'email']);
            $table->index(['added_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};