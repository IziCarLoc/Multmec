<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configuração dos checklists (Ajustes): o técnico da entrada (parte B) e, depois, os modelos da
        // conferência (Expresso e Completo). `needs_review`: item semeado que a oficina ainda precisa revisar.
        if (! Schema::hasTable('checklist_items')) {
            Schema::create('checklist_items', function (Blueprint $table) {
                $table->id();
                $table->string('kind', 20);
                $table->string('template', 20)->nullable();
                $table->string('section', 60);
                $table->string('label', 160);
                $table->boolean('is_fluid')->default(false);
                $table->boolean('requires_detail')->default(false);
                $table->unsignedSmallInteger('position')->default(0);
                $table->boolean('active')->default(true);
                $table->boolean('needs_review')->default(false);
                $table->timestamps();

                $table->index(['kind', 'template', 'active'], 'checklist_items_kind_index');
            });
        }

        // O checklist copiado para a OS quando ela entra na etapa: mudar Ajustes não muda OS antigas.
        if (! Schema::hasTable('os_checklist_items')) {
            Schema::create('os_checklist_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_order_id')->constrained()->cascadeOnDelete();
                $table->foreignId('checklist_item_id')->nullable()->constrained()->nullOnDelete();
                $table->string('kind', 20);
                // Rodada da conferência (um CORRIGIR recomeça a conferência numa rodada nova).
                $table->unsignedSmallInteger('round')->default(1);
                $table->string('section', 60);
                $table->string('label', 160);
                $table->boolean('is_fluid')->default(false);
                $table->boolean('requires_detail')->default(false);
                $table->unsignedSmallInteger('position')->default(0);
                $table->string('state', 12)->nullable();
                $table->string('detail', 255)->nullable();
                $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
                $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('answered_at')->nullable();
                $table->timestamps();

                $table->index(['service_order_id', 'kind', 'round'], 'os_checklist_order_kind_index');
            });
        }

        // Aceites e assinaturas: entrada, aceite do orçamento, recusa, liberação da conferência, retirada.
        // Guarda quem, como, quando (hora do servidor), IP, aparelho, o texto legal da época e os SHA-256 da
        // assinatura e da cópia dos dados que foram assinados.
        if (! Schema::hasTable('consents')) {
            Schema::create('consents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_order_id')->constrained()->cascadeOnDelete();
                $table->string('purpose', 20);
                $table->string('method', 20);
                $table->string('signer_name', 120)->nullable();
                $table->string('signer_document', 14)->nullable();
                $table->string('signer_phone', 20)->nullable();
                $table->string('signer_relation', 60)->nullable();
                $table->text('message_text')->nullable();
                $table->timestamp('message_at')->nullable();
                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->timestamp('signed_at');
                $table->string('signature_path')->nullable();
                $table->char('signature_sha256', 64)->nullable();
                $table->json('snapshot');
                $table->char('snapshot_sha256', 64);
                $table->text('legal_text')->nullable();
                $table->char('legal_text_sha256', 64)->nullable();
                $table->string('pdf_path')->nullable();
                $table->char('pdf_sha256', 64)->nullable();
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['service_order_id', 'purpose'], 'consents_order_purpose_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
        Schema::dropIfExists('os_checklist_items');
        Schema::dropIfExists('checklist_items');
    }
};
