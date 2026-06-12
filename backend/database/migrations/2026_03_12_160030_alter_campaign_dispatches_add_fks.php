<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_dispatches', function (Blueprint $table) {
            if (!Schema::hasColumn('campaign_dispatches', 'contact_id')) {
                return;
            }
            $table->foreign('contact_id')->references('id')->on('contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('campaign_dispatches', function (Blueprint $table) {
            $sm = Schema::getConnection()->getDoctrineSchemaManager();
            foreach ($sm->listTableForeignKeys('campaign_dispatches') as $fk) {
                if (in_array('contact_id', $fk->getLocalColumns(), true)) {
                    $table->dropForeign($fk->getName());
                    break;
                }
            }
        });
    }
};
