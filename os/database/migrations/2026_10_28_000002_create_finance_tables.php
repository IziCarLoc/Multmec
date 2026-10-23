<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Financeiro (entrega 2a): contas a pagar (boletos), a receber com os pagamentos, prejuízos e prestadores
 * externos. Dinheiro sempre em centavos. `legacy_id` guarda o id do documento no artefato antigo, para a
 * importação poder rodar de novo sem duplicar. Cada tabela é criada só se ainda não existe (o DDL do MySQL
 * não volta atrás se a migration parar no meio).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Contas a pagar: boleto de fornecedor (o mesmo cadastro da cotação). Parcelas são linhas separadas
        // ligadas pelo `installment_group`; mexer numa nunca apaga nem recria as outras.
        if (! Schema::hasTable('payables')) {
            Schema::create('payables', function (Blueprint $table) {
                $table->id();
                $table->string('legacy_id', 64)->nullable()->unique();
                $table->foreignId('supplier_id')->constrained();
                $table->string('document_number', 60)->nullable();
                $table->string('description', 200)->nullable();
                $table->date('issue_date')->nullable();
                $table->date('due_date');
                $table->unsignedInteger('amount_cents');
                $table->text('notes')->nullable();
                $table->uuid('installment_group')->nullable()->index();
                $table->unsignedSmallInteger('installment_number')->nullable();
                $table->unsignedSmallInteger('installment_total')->nullable();
                $table->string('status', 10)->default('aberto');
                $table->date('paid_on')->nullable();
                $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['status', 'due_date'], 'payables_status_due_index');
                $table->index('paid_on');
            });
        }

        // Prejuízo: peça avariada, erro de serviço, perda ou a baixa de um "A receber" vencido (inadimplência).
        // `receivable_id` sem chave estrangeira: o lançamento aponta para o prejuízo (`receivables.loss_id`) e as
        // duas tabelas não podem se referenciar na criação.
        if (! Schema::hasTable('losses')) {
            Schema::create('losses', function (Blueprint $table) {
                $table->id();
                $table->string('legacy_id', 64)->nullable()->unique();
                $table->date('occurred_on');
                $table->unsignedInteger('amount_cents');
                $table->string('item', 200);
                $table->string('cause', 16)->default('outro');
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('receivable_id')->nullable()->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('occurred_on');
            });
        }

        // A receber: o serviço cobrado do cliente (uma linha por parcela). A situação sai dos pagamentos
        // (`paid_cents` e `received_on` são o resumo deles, refeito pelo serviço a cada pagamento) e da baixa.
        if (! Schema::hasTable('receivables')) {
            Schema::create('receivables', function (Blueprint $table) {
                $table->id();
                $table->string('legacy_id', 64)->nullable()->unique();
                $table->foreignId('service_order_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
                $table->string('order_number', 20)->nullable();
                $table->date('service_date')->nullable();
                // Como estava no dia (o cadastro do carro pode mudar depois).
                $table->string('plate', 10)->nullable();
                $table->string('vehicle_model', 80)->nullable();
                $table->unsignedInteger('parts_cost_cents')->default(0);
                $table->unsignedInteger('labor_cents')->default(0);
                $table->unsignedInteger('freight_cents')->default(0);
                $table->unsignedInteger('total_cents');
                $table->boolean('customer_brought_part')->default(false);
                // Recebido pela matriz: fica registrado, mas não entra no saldo, no lucro nem no "a receber".
                $table->boolean('received_by_matrix')->default(false);
                $table->string('payment_method', 12)->nullable();
                $table->date('due_date');
                $table->uuid('installment_group')->nullable()->index();
                $table->unsignedSmallInteger('installment_number')->nullable();
                $table->unsignedSmallInteger('installment_total')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedInteger('paid_cents')->default(0);
                $table->date('received_on')->nullable();
                $table->timestamp('written_off_at')->nullable();
                $table->foreignId('loss_id')->nullable()->constrained('losses')->restrictOnDelete();
                $table->foreignId('written_off_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('due_date');
                $table->index('received_on');
            });
        }

        // Pagamentos (parciais ou o integral) de um lançamento do "A receber", cada um com a sua data.
        if (! Schema::hasTable('receivable_payments')) {
            Schema::create('receivable_payments', function (Blueprint $table) {
                $table->id();
                $table->string('legacy_id', 64)->nullable()->unique();
                $table->foreignId('receivable_id')->constrained()->cascadeOnDelete();
                $table->date('paid_on');
                $table->unsignedInteger('amount_cents');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('paid_on');
            });
        }

        // Pagamento a prestador externo (o "Douglas" do artefato e os outros): por OS realizada.
        if (! Schema::hasTable('provider_payments')) {
            Schema::create('provider_payments', function (Blueprint $table) {
                $table->id();
                $table->string('legacy_id', 64)->nullable()->unique();
                $table->foreignId('supplier_id')->constrained();
                $table->foreignId('service_order_id')->nullable()->constrained()->nullOnDelete();
                $table->string('order_number', 20)->nullable();
                $table->string('description', 200)->nullable();
                $table->string('plate', 10)->nullable();
                $table->unsignedInteger('amount_cents');
                $table->date('due_date');
                $table->date('paid_on')->nullable();
                $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('due_date');
                $table->index('paid_on');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_payments');
        Schema::dropIfExists('receivable_payments');
        Schema::dropIfExists('receivables');
        Schema::dropIfExists('losses');
        Schema::dropIfExists('payables');
    }
};
