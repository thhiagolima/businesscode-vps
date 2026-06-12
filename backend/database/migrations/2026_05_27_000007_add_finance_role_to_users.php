<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('user','admin','superadmin','finance') NOT NULL DEFAULT 'user'");
    }

    public function down(): void
    {
        DB::statement("UPDATE users SET role = 'admin' WHERE role = 'finance'");
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('user','admin','superadmin') NOT NULL DEFAULT 'user'");
    }
};
