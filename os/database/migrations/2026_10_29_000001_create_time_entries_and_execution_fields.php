<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Apontamento de tempo (Iniciar/Pausar). Esquecido aberto: fechado à noite e marcado para conferir.
        if (! Schema::hasTable('time_entries')) {
            Schema::create('time_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_order_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained();
                $table->timestamp('started_at');
                $table->timestamp('ended_at')->nullable();
                $table->boolean('auto_closed')->default(false);
                $table->timestamps();

                $table->index(['service_order_id', 'user_id'], 'time_entries_order_user_index');
                $table->index(['user_id', 'ended_at'], 'time_entries_open_index');
            });
        }

        // Foto ligada à peça (peça velha / peça nova).
        Schema::table('media', function (Blueprint $table) {
            if (! Schema::hasColumn('media', 'os_item_id')) {
                $table->foreignId('os_item_id')->nullable()->after('service_order_id')->constrained('os_items')->nullOnDelete();
            }
        });

        // Peça: recebida (quem e quando) ou não usada no serviço.
        Schema::table('os_items', function (Blueprint $table) {
            if (! Schema::hasColumn('os_items', 'received_at')) {
                $table->timestamp('received_at')->nullable()->after('approval');
                $table->foreignId('received_by')->nullable()->after('received_at')->constrained('users')->nullOnDelete();
                $table->boolean('not_used')->default(false)->after('received_by');
            }
        });

        // Execução e conferência: torque das rodas, test drive e a rodada/modelo da conferência.
        Schema::table('service_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('service_orders', 'torque_nm')) {
                $table->unsignedSmallInteger('torque_nm')->nullable()->after('requires_test_drive');
                $table->string('torque_wrench', 30)->nullable()->after('torque_nm');
                $table->timestamp('work_finished_at')->nullable()->after('torque_wrench');
                $table->foreignId('work_finished_by')->nullable()->after('work_finished_at')->constrained('users')->nullOnDelete();

                $table->unsignedInteger('test_drive_km_before')->nullable()->after('work_finished_by');
                $table->unsignedInteger('test_drive_km_after')->nullable()->after('test_drive_km_before');
                $table->timestamp('test_drive_started_at')->nullable()->after('test_drive_km_after');
                $table->timestamp('test_drive_ended_at')->nullable()->after('test_drive_started_at');
                $table->foreignId('test_drive_driver_id')->nullable()->after('test_drive_ended_at')->constrained('users')->nullOnDelete();
                $table->string('test_drive_route', 200)->nullable()->after('test_drive_driver_id');

                $table->string('final_check_template', 12)->nullable()->after('test_drive_route');
                $table->unsignedSmallInteger('final_check_round')->default(0)->after('final_check_template');
                $table->foreignId('final_checker_id')->nullable()->after('final_check_round')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_finished_by');
            $table->dropConstrainedForeignId('test_drive_driver_id');
            $table->dropConstrainedForeignId('final_checker_id');
            $table->dropColumn(['torque_nm', 'torque_wrench', 'work_finished_at', 'test_drive_km_before', 'test_drive_km_after', 'test_drive_started_at', 'test_drive_ended_at', 'test_drive_route', 'final_check_template', 'final_check_round']);
        });

        Schema::table('os_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('received_by');
            $table->dropColumn(['received_at', 'not_used']);
        });

        Schema::table('media', function (Blueprint $table) {
            $table->dropConstrainedForeignId('os_item_id');
        });

        Schema::dropIfExists('time_entries');
    }
};
