<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Endereço do cliente (o cadastro do artefato do Financeiro tinha). Texto livre.
        if (! Schema::hasColumn('customers', 'address')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->string('address', 255)->nullable()->after('email');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'address')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('address');
            });
        }
    }
};
