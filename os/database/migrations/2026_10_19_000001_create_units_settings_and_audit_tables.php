<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Unidade = oficina. Liberdade (padrão) e Matriz; cada uma com seus dados e sua numeração de OS.
        if (! Schema::hasTable('units')) {
            Schema::create('units', function (Blueprint $table) {
                $table->id();
                $table->string('code', 10)->unique();
                $table->string('name', 60);
                $table->string('trade_name', 120);
                $table->string('legal_name', 160)->nullable();
                $table->string('cnpj', 14)->nullable();
                $table->string('address', 200)->nullable();
                $table->string('city', 80)->nullable();
                $table->string('phone', 30)->nullable();
                // manual = número digitado do sistema atual (R65) ao abrir a OS; auto = o sistema numera.
                $table->string('numbering_mode', 10)->default('manual');
                $table->unsignedInteger('next_number')->nullable();
                $table->boolean('is_default')->default(false);
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        // Ajustes editáveis na tela (alçada, garantia, textos dos termos...). Valor em JSON.
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->string('key', 80)->primary();
                $table->text('value')->nullable();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        // Última vez que cada rotina do agendador rodou (o cron da Hostinger está vivo?).
        if (! Schema::hasTable('system_heartbeats')) {
            Schema::create('system_heartbeats', function (Blueprint $table) {
                $table->string('name', 60)->primary();
                $table->timestamp('ran_at');
            });
        }

        // Quem mudou o quê (ajustes, usuários e, depois, OS). Só created_at: registro não se edita.
        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table) {
                $table->id();
                $table->string('auditable_type');
                // Texto: os ajustes têm chave em texto ("warranty_days"); os demais registros, número.
                $table->string('auditable_id', 80);
                $table->string('action', 40);
                $table->text('reason')->nullable();
                $table->json('changes')->nullable();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->timestamp('created_at')->nullable();

                $table->index(['auditable_type', 'auditable_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('system_heartbeats');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('units');
    }
};
