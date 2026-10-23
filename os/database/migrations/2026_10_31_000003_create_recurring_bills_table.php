<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contas fixas (Financeiro 2b): modelos que geram a conta do mês no "A pagar" (salário, aluguel, energia...).
 * Cada conta gerada guarda o modelo e o mês (`competence`, AAAA-MM): o par é único, então gerar de novo nunca
 * duplica. `generated_through` é o último mês já gerado: a conta excluída no A pagar não volta sozinha.
 * A categoria (folha, fixo, imposto, sócios, outros) vai para a conta e é o que a cascata do mês soma.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('recurring_bills')) {
            Schema::create('recurring_bills', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supplier_id')->constrained();
                $table->string('description', 200);
                $table->string('category', 10);
                $table->unsignedTinyInteger('due_day');
                $table->unsignedInteger('amount_cents');
                $table->boolean('active')->default(true);
                // Primeiro mês que gera conta (AAAA-MM) e o último já gerado.
                $table->string('first_month', 7);
                $table->string('generated_through', 7)->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('active');
            });
        }

        $columns = [
            'recurring_bill_id' => fn (Blueprint $table) => $table->foreignId('recurring_bill_id')->nullable()->constrained()->nullOnDelete(),
            'competence' => fn (Blueprint $table) => $table->string('competence', 7)->nullable(),
            // Vazio = boleto de fornecedor (peças): o custo já entra pela OS, não pela cascata.
            'category' => fn (Blueprint $table) => $table->string('category', 10)->nullable(),
        ];

        foreach ($columns as $name => $add) {
            if (! Schema::hasColumn('payables', $name)) {
                Schema::table('payables', fn (Blueprint $table) => $add($table));
            }
        }

        if (! Schema::hasIndex('payables', 'payables_recurring_competence_unique')) {
            Schema::table('payables', function (Blueprint $table) {
                $table->unique(['recurring_bill_id', 'competence'], 'payables_recurring_competence_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('payables', 'payables_recurring_competence_unique')) {
            Schema::table('payables', fn (Blueprint $table) => $table->dropUnique('payables_recurring_competence_unique'));
        }

        if (Schema::hasColumn('payables', 'recurring_bill_id')) {
            Schema::table('payables', fn (Blueprint $table) => $table->dropConstrainedForeignId('recurring_bill_id'));
        }

        foreach (['competence', 'category'] as $name) {
            if (Schema::hasColumn('payables', $name)) {
                Schema::table('payables', fn (Blueprint $table) => $table->dropColumn($name));
            }
        }

        Schema::dropIfExists('recurring_bills');
    }
};
