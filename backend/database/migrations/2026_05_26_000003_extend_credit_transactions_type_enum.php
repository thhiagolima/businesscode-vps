<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Expand credit_transactions.type enum to include reserve/release so
        // CreditService::reserve() and ::release() can write their rows.
        DB::statement("ALTER TABLE `credit_transactions` MODIFY COLUMN `type` ENUM('debit','credit','reserve','release') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `credit_transactions` MODIFY COLUMN `type` ENUM('debit','credit') NOT NULL");
    }
};
