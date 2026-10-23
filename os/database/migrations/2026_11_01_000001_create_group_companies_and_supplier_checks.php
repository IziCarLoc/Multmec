<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conferência de compras (Financeiro 2b): empresas do grupo (locadora, oficina do sócio) e a conferência do
 * fornecedor (quem conferiu CNPJ e telefone, e os outros CNPJs que podem receber os boletos dele). Pode rodar de
 * novo: cada tabela e coluna só é criada se ainda não existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Empresas do mesmo dono/sócios além da oficina: nota ou boleto no CNPJ delas não é golpe, mas também não é
        // despesa da oficina. A própria oficina é o CNPJ das unidades (Ajustes > Oficina e numeração).
        if (! Schema::hasTable('group_companies')) {
            Schema::create('group_companies', function (Blueprint $table) {
                $table->id();
                $table->string('name', 80);
                $table->string('cnpj', 14)->unique();
                $table->string('role', 10)->default('outra');
                $table->boolean('active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        Schema::table('suppliers', function (Blueprint $table) {
            if (! Schema::hasColumn('suppliers', 'confirmed_at')) {
                // Quando o gestor conferiu o CNPJ (cartão CNPJ) e o telefone deste fornecedor. Mudar o CNPJ desfaz.
                $table->timestamp('confirmed_at')->nullable()->after('active');
                $table->foreignId('confirmed_by')->nullable()->after('confirmed_at')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('suppliers', 'authorized_payees')) {
                // Outros CNPJs que podem receber os boletos deste fornecedor (filial, banco, factoring), separados por vírgula.
                $table->text('authorized_payees')->nullable()->after('confirmed_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['confirmed_at', 'authorized_payees']);
        });
        Schema::dropIfExists('group_companies');
    }
};
