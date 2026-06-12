<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE funnel_nodes MODIFY COLUMN type ENUM('start','message','wait','condition','tag','transfer_human','ai_reply') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE funnel_nodes MODIFY COLUMN type ENUM('start','message','wait','condition','tag') NOT NULL");
    }
};
