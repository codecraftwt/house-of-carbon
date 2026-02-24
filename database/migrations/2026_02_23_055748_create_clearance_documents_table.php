<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('clearance_documents', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('clearance_id'); // links clearances.id (no FK)

            // matches React "name" field
            $table->string('doc_key', 50);   // bill_of_entry, invoice, packing_list, duty_receipt, release_order
            $table->string('doc_type', 100); // display name: Bill of Entry, Invoice Copy, etc.

            $table->boolean('uploaded')->default(false);

            $table->string('file_path')->nullable();      // storage path
            $table->string('original_name')->nullable();  // original file name
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            $table->unsignedBigInteger('uploaded_by')->nullable(); // user id
            $table->date('uploaded_at')->nullable();

            $table->timestamps();

            $table->unique(['clearance_id', 'doc_key']);
            $table->index('clearance_id');
            $table->index('doc_key');
            $table->index('uploaded');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clearance_documents');
    }
};