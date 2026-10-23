<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Boletos de fornecedor (da conferência de compras), a ligação boleto × nota e o destino de cada peça da nota
 * (OS, estoque, uso interno, devolvida ou outra empresa do grupo). O boleto da oficina é a conta do "A pagar"
 * (`payable_id`); o de outra empresa do grupo não entra no "A pagar". Pode rodar de novo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bank_slips')) {
            Schema::create('bank_slips', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('payable_id')->nullable()->constrained()->nullOnDelete();
                // 44 dígitos: identifica o boleto e barra a duplicidade.
                $table->string('barcode', 44)->nullable()->unique();
                $table->string('digitable_line', 48)->nullable();
                $table->string('bank_code', 3)->nullable();
                $table->unsignedInteger('amount_cents');
                $table->date('due_date');
                $table->string('document_number', 40)->nullable();
                // Quem recebe e quem paga, como aparecem no papel, no app do banco ou no DDA (não vêm no código de barras).
                $table->string('payee_name', 80)->nullable();
                $table->string('payee_cnpj', 14)->nullable();
                $table->string('payer_cnpj', 14)->nullable();
                $table->string('status', 12)->default('aberto');
                // Quando o gestor confirmou no app do banco o nome e o CNPJ de quem recebe.
                $table->date('bank_checked_on')->nullable();
                // Vazio = boleto da oficina; senão, de outra empresa do grupo.
                $table->foreignId('group_company_id')->nullable()->constrained()->nullOnDelete();
                $table->date('paid_on')->nullable();
                $table->unsignedInteger('paid_cents')->nullable();
                // Boleto de empresa do grupo: quem pagou (oficina = fica a receber da empresa; empresa = só registro).
                $table->string('paid_by_party', 8)->nullable();
                $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
                // Liberado para pagar com ocorrência grave (motivo escrito), ou valor pago diferente explicado.
                $table->text('released_reason')->nullable();
                $table->date('released_on')->nullable();
                // A conta do "A pagar" que já existia e foi adotada pelo boleto (para devolver como era se o boleto sair).
                $table->text('adopted_payable')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('created_role', 12)->nullable();
                $table->string('canceled_role', 12)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['status', 'due_date'], 'bank_slips_status_due_index');
            });
        }

        // Boleto ↔ nota (um boleto pode cobrir várias notas e uma nota pode ter vários boletos).
        if (! Schema::hasTable('purchase_links')) {
            Schema::create('purchase_links', function (Blueprint $table) {
                $table->id();
                $table->foreignId('bank_slip_id')->constrained()->cascadeOnDelete();
                $table->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();
                $table->foreignId('installment_id')->nullable()->constrained('purchase_invoice_installments')->nullOnDelete();
                $table->unsignedInteger('amount_cents');
                $table->string('source', 6)->default('auto');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('created_role', 12)->nullable();
                $table->timestamps();

                $table->unique(['bank_slip_id', 'purchase_invoice_id'], 'purchase_links_slip_invoice_unique');
            });
        }

        // Item da nota → destino. Na OS, a peça do orçamento (`os_item_id`) quando a pessoa escolhe.
        if (! Schema::hasTable('purchase_allocations')) {
            Schema::create('purchase_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('purchase_invoice_item_id')->constrained()->cascadeOnDelete();
                $table->string('destination', 14)->default('os');
                $table->foreignId('service_order_id')->nullable()->constrained()->cascadeOnDelete();
                $table->foreignId('os_item_id')->nullable()->constrained('os_items')->nullOnDelete();
                $table->foreignId('group_company_id')->nullable()->constrained()->nullOnDelete();
                $table->decimal('quantity', 12, 4);
                $table->integer('cost_cents');
                $table->string('notes', 200)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        Schema::table('os_items', function (Blueprint $table) {
            if (! Schema::hasColumn('os_items', 'cost_from_invoice')) {
                // O custo da peça veio da nota de compra (e se refaz com ela); custo digitado nunca é sobrescrito.
                $table->boolean('cost_from_invoice')->default(false)->after('cost_cents');
            }
        });
    }

    public function down(): void
    {
        Schema::table('os_items', fn (Blueprint $table) => $table->dropColumn('cost_from_invoice'));
        Schema::dropIfExists('purchase_allocations');
        Schema::dropIfExists('purchase_links');
        Schema::dropIfExists('bank_slips');
    }
};
