<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pessoa de contato no fornecedor (o cadastro do artefato do Financeiro tinha "Contato"). Texto livre.
        if (! Schema::hasColumn('suppliers', 'contact_name')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->string('contact_name', 120)->nullable()->after('whatsapp');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('suppliers', 'contact_name')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->dropColumn('contact_name');
            });
        }
    }
};
