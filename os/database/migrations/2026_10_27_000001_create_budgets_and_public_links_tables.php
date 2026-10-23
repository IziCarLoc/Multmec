<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Orçamento congelado (nº 1) e adendos (2, 3...): cópia do que o cliente recebeu, com o SHA-256.
        if (! Schema::hasTable('budgets')) {
            Schema::create('budgets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_order_id')->constrained()->cascadeOnDelete();
                $table->unsignedSmallInteger('number');
                $table->string('kind', 10);
                $table->string('status', 12);

                $table->json('snapshot');
                $table->char('snapshot_sha256', 64);
                $table->unsignedInteger('parts_cents');
                $table->unsignedInteger('labor_cents');
                $table->unsignedInteger('total_cents');
                $table->unsignedInteger('approved_total_cents')->nullable();

                $table->string('payment_terms', 200)->nullable();
                $table->date('expected_date')->nullable();
                $table->date('valid_until')->nullable();

                $table->timestamp('frozen_at')->nullable();
                $table->foreignId('frozen_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('sent_at')->nullable();
                $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('sent_via', 12)->nullable();
                $table->string('pdf_path')->nullable();
                $table->char('pdf_sha256', 64)->nullable();

                // Resposta: quem registrou, como (link, mensagem, presencial, assinatura, alçada) e o aceite gravado.
                $table->timestamp('decided_at')->nullable();
                $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('decision_method', 12)->nullable();
                $table->foreignId('consent_id')->nullable()->constrained('consents')->nullOnDelete();
                $table->boolean('non_original_authorized')->nullable();
                $table->text('refusal_reason')->nullable();
                $table->unsignedInteger('diagnosis_fee_cents')->nullable();

                $table->timestamps();

                $table->unique(['service_order_id', 'number'], 'budgets_order_number_unique');
            });
        }

        // Link para o cliente aprovar ou recusar sem login: vale até a validade, uma resposta só.
        if (! Schema::hasTable('public_links')) {
            Schema::create('public_links', function (Blueprint $table) {
                $table->id();
                $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
                $table->char('token_hash', 64)->unique('public_links_token_unique');
                $table->text('token');
                $table->timestamp('expires_at');
                $table->timestamp('used_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamp('last_viewed_at')->nullable();
                $table->unsignedInteger('views')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        // Item: preço ao cliente (unitário), em qual orçamento entrou e se o cliente aprovou.
        Schema::table('os_items', function (Blueprint $table) {
            if (! Schema::hasColumn('os_items', 'budget_id')) {
                $table->foreignId('budget_id')->nullable()->after('price_cents')->constrained()->nullOnDelete();
            }

            if (! Schema::hasColumn('os_items', 'approval')) {
                $table->string('approval', 10)->nullable()->after('budget_id');
            }
        });

        // Condições do orçamento em montagem (vão congeladas no orçamento enviado).
        Schema::table('service_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('service_orders', 'payment_terms')) {
                $table->string('payment_terms', 200)->nullable()->after('diagnosis_fee_cents');
            }

            if (! Schema::hasColumn('service_orders', 'budget_notes')) {
                $table->text('budget_notes')->nullable()->after('payment_terms');
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropColumn(['payment_terms', 'budget_notes']);
        });

        Schema::table('os_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('budget_id');
            $table->dropColumn('approval');
        });

        Schema::dropIfExists('public_links');
        Schema::dropIfExists('budgets');
    }
};
