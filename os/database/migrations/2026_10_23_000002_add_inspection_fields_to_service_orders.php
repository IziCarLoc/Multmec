<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Vistoria de entrada, parte A (atendimento). Km, combustível e autorizações já estão na OS. */
    private const COLUMNS = [
        'dashboard_lights', 'belongings', 'has_spare_tire', 'has_jack', 'has_triangle', 'has_wheel_wrench',
        'no_visible_damage', 'inspection_sent_at', 'inspection_sent_by', 'inspection_sent_via',
        'inspection_pdf_path', 'inspection_pdf_sha256', 'inspection_snapshot_sha256',
    ];

    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('service_orders', 'dashboard_lights')) {
                // Luzes acesas no painel com o motor ligado ("nenhuma" também é resposta).
                $table->string('dashboard_lights', 255)->nullable();
                // Objetos e acessórios deixados no carro ("nenhum" também é resposta).
                $table->text('belongings')->nullable();
                $table->boolean('has_spare_tire')->nullable();
                $table->boolean('has_jack')->nullable();
                $table->boolean('has_triangle')->nullable();
                $table->boolean('has_wheel_wrench')->nullable();
                $table->boolean('no_visible_damage')->nullable();
                // Vistoria mandada ao cliente pelo WhatsApp: quando, quem, como, o PDF enviado e o resumo do que
                // foi enviado (se a vistoria mudar depois, precisa mandar de novo).
                $table->timestamp('inspection_sent_at')->nullable();
                $table->foreignId('inspection_sent_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('inspection_sent_via', 20)->nullable();
                $table->string('inspection_pdf_path')->nullable();
                $table->char('inspection_pdf_sha256', 64)->nullable();
                $table->char('inspection_snapshot_sha256', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            if (Schema::hasColumn('service_orders', 'inspection_sent_by')) {
                $table->dropForeign(['inspection_sent_by']);
            }

            $table->dropColumn(array_values(array_filter(self::COLUMNS, fn ($c) => Schema::hasColumn('service_orders', $c))));
        });
    }
};
