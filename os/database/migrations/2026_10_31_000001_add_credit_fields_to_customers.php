<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crédito a prazo (Financeiro 2b): tipo do cliente, prazo em dias e limite (centavos), como no cadastro de clientes
 * do financeiro em Node; e a liberação temporária que o gestor dá, com motivo, para vender a prazo a cliente
 * travado ou sem crédito. Cada coluna só entra se ainda não existe (o DDL do MySQL não volta atrás).
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            // avulso (paga na retirada), frota (empresa com prazo), locadora ou revenda.
            'credit_kind' => fn (Blueprint $table) => $table->string('credit_kind', 10)->default('avulso'),
            'credit_days' => fn (Blueprint $table) => $table->unsignedSmallInteger('credit_days')->default(0),
            // Máximo em aberto; 0 = sem crédito (a prazo só com liberação do gestor).
            'credit_limit_cents' => fn (Blueprint $table) => $table->unsignedInteger('credit_limit_cents')->default(0),
            'credit_release_until' => fn (Blueprint $table) => $table->date('credit_release_until')->nullable(),
            'credit_release_reason' => fn (Blueprint $table) => $table->string('credit_release_reason', 200)->nullable(),
            'credit_released_by' => fn (Blueprint $table) => $table->foreignId('credit_released_by')->nullable()->constrained('users')->nullOnDelete(),
        ];

        foreach ($columns as $name => $add) {
            if (! Schema::hasColumn('customers', $name)) {
                Schema::table('customers', fn (Blueprint $table) => $add($table));
            }
        }

        // A Izi Car é uma locadora (o prazo e o limite o gestor define na tela Crédito).
        DB::table('customers')->where('is_izi_fleet', true)->where('credit_kind', 'avulso')->update(['credit_kind' => 'locadora']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'credit_released_by')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropConstrainedForeignId('credit_released_by');
            });
        }

        foreach (['credit_release_reason', 'credit_release_until', 'credit_limit_cents', 'credit_days', 'credit_kind'] as $name) {
            if (Schema::hasColumn('customers', $name)) {
                Schema::table('customers', fn (Blueprint $table) => $table->dropColumn($name));
            }
        }
    }
};
