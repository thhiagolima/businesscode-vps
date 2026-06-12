<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('funnel_nodes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('funnel_id');
            $table->string('node_id');
            $table->enum('type', ['start', 'message', 'wait', 'condition', 'tag']);
            $table->string('label')->nullable();
            $table->json('config')->nullable();
            $table->float('position_x')->default(0);
            $table->float('position_y')->default(0);
            $table->timestamps();
            $table->foreign('funnel_id')->references('id')->on('funnels')->cascadeOnDelete();
            $table->unique(['funnel_id', 'node_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('funnel_nodes'); }
};
