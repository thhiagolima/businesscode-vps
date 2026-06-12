<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            if (!Schema::hasColumn('campaigns', 'contact_list_id')) {
                return;
            }
            $table->foreign('contact_list_id')->references('id')->on('contact_lists')->nullOnDelete();
            $table->index('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $sm = Schema::getConnection()->getDoctrineSchemaManager();
            foreach ($sm->listTableForeignKeys('campaigns') as $fk) {
                if (in_array('contact_list_id', $fk->getLocalColumns(), true)) {
                    $table->dropForeign($fk->getName());
                    break;
                }
            }
            $table->dropIndex(['scheduled_at']);
        });
    }
};
