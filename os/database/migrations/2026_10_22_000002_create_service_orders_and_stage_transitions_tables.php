<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('service_orders')) {
            Schema::create('service_orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('unit_id')->constrained();
                // Número da OS na unidade: o do sistema atual (R65), digitado, ou o automático (Ajustes).
                $table->unsignedInteger('number');
                $table->string('stage', 30);
                $table->timestamp('stage_changed_at')->nullable();
                $table->foreignId('customer_id')->constrained();
                $table->foreignId('vehicle_id')->constrained();

                $table->date('entry_date');
                $table->date('expected_date')->nullable();
                $table->timestamp('delivered_at')->nullable();

                // Queixa nas palavras do cliente; diagnóstico em linguagem de cliente.
                $table->text('complaint');
                $table->text('diagnosis')->nullable();
                $table->text('notes')->nullable();

                $table->unsignedInteger('km_in')->nullable();
                $table->string('fuel_in', 5)->nullable();
                $table->unsignedInteger('km_out')->nullable();
                $table->string('fuel_out', 5)->nullable();

                // Quem aprova o orçamento (cliente, Izi pela alçada, investidor, outro) e o contato dele.
                $table->string('approver_kind', 12)->nullable();
                $table->string('approver_name', 120)->nullable();
                $table->string('approver_whatsapp', 20)->nullable();
                $table->boolean('test_drive_authorized')->nullable();
                $table->boolean('diagnosis_charged')->nullable();
                $table->unsignedInteger('diagnosis_fee_cents')->nullable();

                // Responsáveis por fase (usuários; nunca texto livre).
                $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();

                $table->boolean('has_wheel_work')->default(false);
                $table->boolean('requires_test_drive')->default(false);

                // Retorno em garantia de outra OS, com a causa.
                $table->foreignId('warranty_return_of')->nullable()->constrained('service_orders')->nullOnDelete();
                $table->string('warranty_cause', 20)->nullable();

                // Nota fiscal: não trava a OS; fica pendente até ser emitida no sistema fiscal.
                $table->string('invoice_status', 10)->default('pendente');
                $table->string('invoice_service_number', 30)->nullable();
                $table->string('invoice_parts_number', 30)->nullable();

                $table->text('cancel_reason')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['unit_id', 'number'], 'service_orders_unit_number_unique');
                $table->index('stage');
            });
        }

        // Trilha das etapas: de onde, para onde, quem, quando e por quê. Registro não se edita.
        if (! Schema::hasTable('stage_transitions')) {
            Schema::create('stage_transitions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_order_id')->constrained()->cascadeOnDelete();
                $table->string('from_stage', 30)->nullable();
                $table->string('to_stage', 30);
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->text('reason')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['service_order_id', 'id'], 'stage_transitions_order_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_transitions');
        Schema::dropIfExists('service_orders');
    }
};
