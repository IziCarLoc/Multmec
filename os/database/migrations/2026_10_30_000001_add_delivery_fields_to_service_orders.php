<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Entrega: o que foi feito com as peças substituídas, quem entregou e a nota (não trava a OS).
        Schema::table('service_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('service_orders', 'replaced_parts')) {
                $table->string('replaced_parts', 12)->nullable()->after('fuel_out');
                $table->foreignId('delivered_by')->nullable()->after('delivered_at')->constrained('users')->nullOnDelete();
                $table->timestamp('invoice_updated_at')->nullable()->after('invoice_parts_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivered_by');
            $table->dropColumn(['replaced_parts', 'invoice_updated_at']);
        });
    }
};
