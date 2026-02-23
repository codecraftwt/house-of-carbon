<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Convert existing "Pending" rows first (if any)
        DB::statement("UPDATE quotations SET status='ChangesRequested' WHERE status='Pending'");

        // Update ENUM allowed values
        DB::statement("ALTER TABLE quotations MODIFY status
            ENUM('Draft','Sent','Approved','Rejected','ChangesRequested')
            NOT NULL DEFAULT 'Draft'
        ");
    }

    public function down(): void
    {
        // revert enum back (and map ChangesRequested back to Pending)
        DB::statement("UPDATE quotations SET status='Pending' WHERE status='ChangesRequested'");

        DB::statement("ALTER TABLE quotations MODIFY status
            ENUM('Draft','Sent','Pending','Approved','Rejected')
            NOT NULL DEFAULT 'Draft'
        ");
    }
};
