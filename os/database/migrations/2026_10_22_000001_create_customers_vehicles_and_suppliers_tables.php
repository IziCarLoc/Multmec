<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cliente: quem traz o carro (e recebe a vistoria e o orçamento pelo WhatsApp). A Izi Car é um cliente
        // como os outros, marcado `is_izi_fleet` (aprovação interna por alçada).
        if (! Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                // Só dígitos, com DDD e sem o 55 (o link do WhatsApp põe o 55).
                $table->string('whatsapp', 20)->nullable();
                $table->string('phone2', 20)->nullable();
                // CPF/CNPJ só com dígitos; pedido no aceite e na retirada, não na abertura.
                $table->string('document', 14)->nullable();
                $table->string('email', 120)->nullable();
                $table->boolean('is_izi_fleet')->default(false);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index('name');
                $table->index('whatsapp');
            });
        }

        // Veículo: a placa é a chave do dia a dia. `plate` é como foi digitada (só letras e números);
        // `plate_key` é a forma Mercosul (ABC1234 e ABC1C34 são o mesmo carro) e é única.
        if (! Schema::hasTable('vehicles')) {
            Schema::create('vehicles', function (Blueprint $table) {
                $table->id();
                $table->string('plate', 7);
                $table->string('plate_key', 7)->unique();
                $table->string('brand_model', 80)->nullable();
                $table->string('year', 9)->nullable();
                $table->string('color', 30)->nullable();
                $table->string('chassis', 17)->nullable();
                $table->string('engine', 40)->nullable();
                $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
                // Frota da Izi Car: o cliente é a Izi; o investidor (dono do carro) recebe o orçamento pelo WhatsApp.
                $table->boolean('is_izi_fleet')->default(false);
                $table->string('investor_name', 120)->nullable();
                $table->string('investor_whatsapp', 20)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        // Fornecedor de peças e prestador externo de serviço (eletricista, ar-condicionado...) no mesmo
        // cadastro: `kind` diz o que ele faz. É o mesmo cadastro da cotação e, depois, do Financeiro.
        if (! Schema::hasTable('suppliers')) {
            Schema::create('suppliers', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('kind', 10)->default('pecas');
                $table->string('whatsapp', 20)->nullable();
                $table->string('email', 120)->nullable();
                $table->string('document', 14)->nullable();
                // Linhas que atende (marcas, tipos de peça) ou especialidade do prestador.
                $table->text('lines')->nullable();
                $table->string('lead_time', 60)->nullable();
                $table->string('payment_terms', 120)->nullable();
                $table->string('pix_key', 120)->nullable();
                $table->text('notes')->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();

                $table->index('name');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('customers');
    }
};
