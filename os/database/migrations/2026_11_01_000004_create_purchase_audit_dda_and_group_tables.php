<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aceites das ocorrências ("Está certo, conferi"), o DDA importado do banco e o acerto entre a oficina e as
 * empresas do grupo. O histórico (trilha) fica em `audit_logs`, só de inclusão. Pode rodar de novo.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Situação atual do aceite; o histórico de cada aceite fica em audit_logs. `state` é a impressão digital do
        // fato aceito (valor, boletos do grupo...): se o fato muda, o aceite deixa de valer.
        if (! Schema::hasTable('audit_acceptances')) {
            Schema::create('audit_acceptances', function (Blueprint $table) {
                $table->id();
                $table->string('occurrence_key', 120)->unique();
                $table->text('reason');
                $table->text('state');
                $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        // DDA: a lista do BANCO de todo boleto registrado contra o CNPJ. `scope` = empresa do grupo ou 0 (oficina).
        if (! Schema::hasTable('dda_imports')) {
            Schema::create('dda_imports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('group_company_id')->nullable()->constrained()->nullOnDelete();
                $table->unsignedBigInteger('scope')->default(0);
                $table->string('file_name', 120)->nullable();
                $table->string('format', 8);
                // Data de geração que o próprio arquivo informa (quando informa).
                $table->date('generated_on')->nullable();
                $table->date('imported_on');
                $table->unsignedInteger('titles_count')->default(0);
                $table->unsignedInteger('new_count')->default(0);
                $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['scope', 'imported_on'], 'dda_imports_scope_date_index');
            });
        }

        if (! Schema::hasTable('dda_titles')) {
            Schema::create('dda_titles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('group_company_id')->nullable()->constrained()->nullOnDelete();
                $table->unsignedBigInteger('scope')->default(0);
                // Código de barras (44) ou, sem ele, valor|vencimento|CNPJ de quem recebe.
                $table->string('title_key', 80);
                $table->string('barcode', 44)->nullable()->index();
                $table->string('bank_code', 3)->nullable();
                $table->unsignedInteger('amount_cents');
                $table->date('due_date');
                $table->string('payee_cnpj', 14)->nullable();
                $table->string('payee_name', 80)->nullable();
                $table->string('drawer_cnpj', 14)->nullable();
                $table->string('drawer_name', 80)->nullable();
                $table->string('payer_cnpj', 14)->nullable();
                $table->string('document_number', 30)->nullable();
                $table->date('issue_date')->nullable();
                $table->string('format', 8);
                $table->foreignId('first_import_id')->constrained('dda_imports')->cascadeOnDelete();
                $table->foreignId('last_import_id')->constrained('dda_imports')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['scope', 'title_key'], 'dda_titles_scope_key_unique');
            });
        }

        // Acerto entre a oficina e as empresas do grupo. a_receber: a oficina pagou ou forneceu algo que é da outra
        // empresa; a_pagar: a outra empresa pagou algo que é da oficina. `cash`: saiu dinheiro da conta da oficina no dia.
        if (! Schema::hasTable('group_settlements')) {
            Schema::create('group_settlements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('group_company_id')->constrained();
                $table->string('direction', 10)->default('a_receber');
                $table->string('source', 8)->default('manual');
                $table->foreignId('bank_slip_id')->nullable()->constrained()->nullOnDelete();
                // Peça entregue à empresa (sem chave estrangeira de propósito: o destino é desfeito pelo serviço).
                $table->unsignedBigInteger('purchase_allocation_id')->nullable()->index();
                $table->string('description', 160);
                $table->unsignedInteger('amount_cents');
                $table->date('occurred_on');
                $table->boolean('cash')->default(false);
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        // Devoluções (parciais ou total) de um acerto.
        if (! Schema::hasTable('group_settlement_returns')) {
            Schema::create('group_settlement_returns', function (Blueprint $table) {
                $table->id();
                $table->foreignId('group_settlement_id')->constrained()->cascadeOnDelete();
                $table->date('returned_on');
                $table->unsignedInteger('amount_cents');
                $table->string('notes', 200)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('group_settlement_returns');
        Schema::dropIfExists('group_settlements');
        Schema::dropIfExists('dda_titles');
        Schema::dropIfExists('dda_imports');
        Schema::dropIfExists('audit_acceptances');
    }
};
