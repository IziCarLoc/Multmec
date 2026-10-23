<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notas fiscais de compra (NF-e do fornecedor), com as parcelas (duplicatas) e os itens. Dinheiro em centavos.
 * O XML original fica guardado comprimido (gzip em base64) com o SHA-256: é a prova da nota. Pode rodar de novo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_invoices')) {
            Schema::create('purchase_invoices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supplier_id')->constrained();
                // 44 posições; vazio em nota digitada sem a chave.
                $table->string('access_key', 44)->nullable()->unique();
                $table->string('number', 12);
                $table->string('series', 4)->default('');
                $table->date('issue_date');
                // vNF: o que realmente se paga. Com IBS/CBS "por fora", o vNFTot quando é diferente.
                $table->unsignedInteger('total_cents');
                $table->unsignedInteger('total_with_taxes_cents')->nullable();
                $table->unsignedInteger('products_cents')->nullable();
                $table->unsignedInteger('freight_cents')->nullable();
                $table->unsignedInteger('discount_cents')->nullable();
                // A nota informa pagamento imediato (dinheiro, cartão, Pix) e não traz parcelas: não deve vir boleto.
                $table->boolean('paid_upfront')->default(false);
                // Quem recebe o boleto, quando a nota informa (detPag/card/CNPJReceb).
                $table->string('payee_cnpj', 14)->nullable();
                // Destinatário que é outra empresa do grupo (vazio = oficina ou desconhecido).
                $table->foreignId('group_company_id')->nullable()->constrained()->nullOnDelete();
                $table->string('issuer_cnpj', 14)->nullable();
                $table->string('issuer_name', 120)->nullable();
                $table->string('recipient_cnpj', 14)->nullable();
                $table->string('recipient_name', 120)->nullable();
                $table->string('purpose', 12)->default('normal');
                $table->string('status', 10)->default('ativa');
                // sefaz (protocolo, evento ou consulta do gestor: não se desfaz aqui) ou manual.
                $table->string('canceled_source', 8)->nullable();
                $table->string('protocol_status', 5)->nullable();
                $table->string('nature', 120)->nullable();
                $table->text('additional_info')->nullable();
                $table->string('source', 6)->default('xml');
                $table->string('xml_sha256', 64)->nullable();
                $table->longText('xml_gz')->nullable();
                // Consulta da chave no portal da NF-e feita por uma pessoa (a prova da nota sem XML).
                $table->date('portal_checked_on')->nullable();
                $table->string('portal_status', 16)->nullable();
                $table->unsignedInteger('portal_amount_cents')->nullable();
                $table->date('portal_issue_date')->nullable();
                $table->string('portal_recipient', 14)->nullable();
                $table->foreignId('portal_checked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('portal_checked_role', 12)->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('created_role', 12)->nullable();
                $table->timestamps();

                $table->unique(['supplier_id', 'number', 'series'], 'purchase_invoices_supplier_number_unique');
                $table->index('issue_date');
            });
        }

        if (! Schema::hasTable('purchase_invoice_installments')) {
            Schema::create('purchase_invoice_installments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();
                $table->string('number', 20)->nullable();
                $table->date('due_date');
                $table->unsignedInteger('amount_cents');
            });
        }

        if (! Schema::hasTable('purchase_invoice_items')) {
            Schema::create('purchase_invoice_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();
                $table->unsignedSmallInteger('item_number');
                $table->string('code', 60)->nullable();
                $table->string('description', 200);
                $table->string('ncm', 10)->nullable();
                $table->string('cfop', 4)->nullable();
                $table->string('unit', 10)->nullable();
                $table->decimal('quantity', 12, 4);
                $table->unsignedInteger('unit_price_cents');
                $table->unsignedInteger('total_cents');
                $table->unsignedInteger('discount_cents')->default(0);
                // Parte deste item no total da nota (frete, impostos e desconto rateados): o custo real da peça.
                $table->integer('cost_cents');
                // Pedido que a oficina informou na compra (xPed: muitos fornecedores põem o número da OS) e a informação
                // adicional do item (muitos sistemas escrevem a placa aqui).
                $table->string('customer_order', 60)->nullable();
                $table->text('additional_info')->nullable();

                $table->unique(['purchase_invoice_id', 'item_number'], 'purchase_invoice_items_number_unique');
                $table->index('code');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoice_items');
        Schema::dropIfExists('purchase_invoice_installments');
        Schema::dropIfExists('purchase_invoices');
    }
};
