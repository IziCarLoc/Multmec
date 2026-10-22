<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fotos e vídeos da OS. O arquivo recebido fica intacto (`original_*`, com o SHA-256 conferido contra o
        // que o celular calculou); a cópia carimbada e a miniatura são feitas no servidor. Foto refeita não é
        // apagada: fica marcada como substituída (prova).
        if (! Schema::hasTable('media')) {
            Schema::create('media', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_order_id')->constrained()->cascadeOnDelete();
                $table->string('kind', 20);
                // Posição dentro do tipo: canto do carro, item do checklist, roda...
                $table->string('position', 40)->nullable();
                $table->string('caption', 160)->nullable();
                $table->string('original_path');
                $table->char('original_sha256', 64);
                $table->string('stamped_path')->nullable();
                $table->char('stamped_sha256', 64)->nullable();
                $table->string('thumb_path')->nullable();
                $table->string('mime', 40);
                $table->unsignedInteger('size');
                $table->unsignedSmallInteger('width')->nullable();
                $table->unsignedSmallInteger('height')->nullable();
                // Hora do aparelho (pode estar errada) e hora do servidor (a que vale).
                $table->timestamp('taken_at')->nullable();
                $table->timestamp('received_at');
                $table->boolean('is_stale')->default(false);
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->timestamp('replaced_at')->nullable();
                $table->unsignedBigInteger('replaced_by')->nullable();
                $table->timestamps();

                $table->index(['service_order_id', 'kind'], 'media_order_kind_index');
            });
        }

        // Avaria na entrada: a foto (media AVARIA), o local do carro e as marcas tocadas sobre a foto (x e y de
        // 0 a 1, tipo, descrição). As marcas ficam aqui, não na imagem: o PDF desenha numa cópia.
        if (! Schema::hasTable('damages')) {
            Schema::create('damages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_order_id')->constrained()->cascadeOnDelete();
                $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
                $table->string('location', 60);
                $table->json('marks')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('damages');
        Schema::dropIfExists('media');
    }
};
