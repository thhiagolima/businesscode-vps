<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('funnel_edges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('funnel_id');
            $table->string('edge_id');
            $table->string('source_node_id');
            $table->string('target_node_id');
            $table->string('label')->nullable();
            $table->timestamps();
            $table->foreign('funnel_id')->references('id')->on('funnels')->cascadeOnDelete();
        });
    }
    public function down(): void { Schema::dropIfExists('funnel_edges'); }
};
