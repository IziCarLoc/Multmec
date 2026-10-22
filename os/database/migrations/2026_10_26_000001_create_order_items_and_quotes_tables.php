<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Peças e serviços da OS. O mecânico lança no diagnóstico (descrição, quantidade, horas, necessário ou
        // recomendado, detalhe para cotar); o atendimento completa origem, fornecedor/prestador e custo. Preço,
        // orçamento e adendo chegam no incremento 4; peça recebida/não usada e "sem foto", na execução.
        if (! Schema::hasTable('os_items')) {
            Schema::create('os_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_order_id')->constrained()->cascadeOnDelete();
                $table->string('kind', 10);
                $table->string('description', 200);
                $table->decimal('quantity', 8, 2)->default(1);
                $table->string('priority', 12)->default('necessario');
                $table->decimal('hours', 6, 2)->nullable();
                // Para cotar: código, marca preferida e aceitas, lado, observação.
                $table->string('part_code', 60)->nullable();
                $table->string('preferred_brand', 60)->nullable();
                $table->string('accepted_brands', 120)->nullable();
                $table->string('quote_detail', 255)->nullable();
                $table->boolean('needs_quote')->default(true);
                $table->string('origin', 12)->nullable();
                // Fornecedor vencedor da cotação (peça) ou prestador externo (serviço), com o custo.
                $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
                $table->unsignedInteger('cost_cents')->nullable();
                $table->unsignedInteger('price_cents')->nullable();
                $table->string('brand', 60)->nullable();
                $table->string('lead_time', 60)->nullable();
                $table->boolean('requires_photos')->default(true);
                $table->foreignId('mechanic_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedSmallInteger('position')->default(0);
                $table->timestamps();

                $table->index(['service_order_id', 'kind'], 'os_items_order_kind_index');
            });
        }

        // Pedido de cotação COT-AAAA-NNNN: itens, fornecedores (com a hora do envio de cada um) e respostas.
        if (! Schema::hasTable('quote_requests')) {
            Schema::create('quote_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_order_id')->constrained()->cascadeOnDelete();
                $table->string('code', 20)->unique();
                $table->string('max_delivery', 60)->nullable();
                $table->string('payment_terms', 120)->nullable();
                $table->timestamp('answer_until')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('quote_request_items')) {
            Schema::create('quote_request_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('quote_request_id')->constrained()->cascadeOnDelete();
                $table->foreignId('os_item_id')->constrained('os_items')->cascadeOnDelete();

                $table->unique(['quote_request_id', 'os_item_id'], 'quote_request_items_unique');
            });
        }

        if (! Schema::hasTable('quote_sends')) {
            Schema::create('quote_sends', function (Blueprint $table) {
                $table->id();
                $table->foreignId('quote_request_id')->constrained()->cascadeOnDelete();
                $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
                $table->timestamp('sent_at')->nullable();
                $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['quote_request_id', 'supplier_id'], 'quote_sends_unique');
            });
        }

        if (! Schema::hasTable('quote_responses')) {
            Schema::create('quote_responses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('quote_request_id')->constrained()->cascadeOnDelete();
                $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
                $table->foreignId('os_item_id')->constrained('os_items')->cascadeOnDelete();
                $table->string('status', 14);
                $table->string('brand', 60)->nullable();
                $table->string('origin', 12)->nullable();
                $table->unsignedInteger('cost_cents')->nullable();
                $table->string('lead_time', 60)->nullable();
                $table->string('warranty', 60)->nullable();
                $table->boolean('is_winner')->default(false);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['quote_request_id', 'supplier_id', 'os_item_id'], 'quote_responses_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_responses');
        Schema::dropIfExists('quote_sends');
        Schema::dropIfExists('quote_request_items');
        Schema::dropIfExists('quote_requests');
        Schema::dropIfExists('os_items');
    }
};
