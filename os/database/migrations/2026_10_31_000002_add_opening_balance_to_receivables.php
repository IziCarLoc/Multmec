<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Saldo anterior": a dívida de antes do sistema, lançada no A receber do cliente. Entra no que ele deve (e é
 * quitada primeiro), mas não conta como faturamento nem como lucro.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('receivables', 'opening_balance')) {
            Schema::table('receivables', function (Blueprint $table) {
                $table->boolean('opening_balance')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('receivables', 'opening_balance')) {
            Schema::table('receivables', fn (Blueprint $table) => $table->dropColumn('opening_balance'));
        }
    }
};
