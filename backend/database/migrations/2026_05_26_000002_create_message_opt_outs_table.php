<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('message_opt_outs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->enum('channel', ['sms', 'voice', 'email', 'all']);
            $t->string('identifier', 255);
            $t->char('identifier_hash', 64);
            $t->string('reason', 50);
            $t->foreignId('source_dispatch_id')->nullable()->constrained('message_dispatches')->nullOnDelete();
            $t->timestamps();

            $t->unique(['tenant_id', 'channel', 'identifier_hash'], 'msg_opt_tenant_chan_id_unique');
            $t->index(['tenant_id', 'identifier_hash'], 'msg_opt_tenant_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_opt_outs');
    }
};
