<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Campaign dispatches — frequently queried by campaign_id + status
        if (!$this->hasIndex('campaign_dispatches', 'campaign_dispatches_campaign_status_idx')) {
            Schema::table('campaign_dispatches', function (Blueprint $table) {
                $table->index(['campaign_id', 'status'], 'campaign_dispatches_campaign_status_idx');
            });
        }

        // Campaign dispatches — webhook lookup by external_message_id
        if (!$this->hasIndex('campaign_dispatches', 'campaign_dispatches_external_msg_idx')) {
            Schema::table('campaign_dispatches', function (Blueprint $table) {
                $table->index('external_message_id', 'campaign_dispatches_external_msg_idx');
            });
        }

        // Conversations — ordered by last_message_at for inbox
        if (!$this->hasIndex('conversations', 'conversations_tenant_last_msg_idx')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->index(['tenant_id', 'last_message_at'], 'conversations_tenant_last_msg_idx');
            });
        }

        // Conversation messages — chat history
        if (!$this->hasIndex('conversation_messages', 'conv_msgs_conv_created_idx')) {
            Schema::table('conversation_messages', function (Blueprint $table) {
                $table->index(['conversation_id', 'created_at'], 'conv_msgs_conv_created_idx');
            });
        }

        // Contacts — search by phone
        if (!$this->hasIndex('contacts', 'contacts_phone_idx')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->index('phone', 'contacts_phone_idx');
            });
        }

        // Audit logs — query by tenant + created_at
        if (!$this->hasIndex('audit_logs', 'audit_logs_tenant_created_idx')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->index(['tenant_id', 'created_at'], 'audit_logs_tenant_created_idx');
            });
        }

        // Credit transactions — query by tenant + type + created_at
        if (!$this->hasIndex('credit_transactions', 'credit_tx_tenant_type_created_idx')) {
            Schema::table('credit_transactions', function (Blueprint $table) {
                $table->index(['tenant_id', 'type', 'created_at'], 'credit_tx_tenant_type_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('campaign_dispatches', function (Blueprint $table) {
            $table->dropIndex('campaign_dispatches_campaign_status_idx');
            $table->dropIndex('campaign_dispatches_external_msg_idx');
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_tenant_last_msg_idx');
        });
        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->dropIndex('conv_msgs_conv_created_idx');
        });
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('contacts_phone_idx');
        });
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_tenant_created_idx');
        });
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->dropIndex('credit_tx_tenant_type_created_idx');
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $indexes = collect(\DB::select("SHOW INDEX FROM {$table}"))->pluck('Key_name')->unique();
        return $indexes->contains($indexName);
    }
};
