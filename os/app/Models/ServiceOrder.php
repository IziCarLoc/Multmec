<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\Stage;
use App\Support\LicensePlate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ordem de serviço. A etapa só muda por App\Services\Orders\StageMachine (que confere as travas e grava a
 * trilha); nunca com `update(['stage' => ...])` direto.
 */
class ServiceOrder extends Model
{
    protected $fillable = [
        'unit_id',
        'number',
        'stage',
        'stage_changed_at',
        'customer_id',
        'vehicle_id',
        'entry_date',
        'expected_date',
        'delivered_at',
        'complaint',
        'diagnosis',
        'notes',
        'km_in',
        'fuel_in',
        'km_out',
        'fuel_out',
        'approver_kind',
        'approver_name',
        'approver_whatsapp',
        'test_drive_authorized',
        'diagnosis_charged',
        'diagnosis_fee_cents',
        'received_by',
        'responsible_id',
        'has_wheel_work',
        'requires_test_drive',
        'warranty_return_of',
        'warranty_cause',
        'invoice_status',
        'invoice_service_number',
        'invoice_parts_number',
        'cancel_reason',
        'cancelled_at',
        'created_by',
        'dashboard_lights',
        'belongings',
        'has_spare_tire',
        'has_jack',
        'has_triangle',
        'has_wheel_wrench',
        'no_visible_damage',
        'inspection_sent_at',
        'inspection_sent_by',
        'inspection_sent_via',
        'inspection_pdf_path',
        'inspection_pdf_sha256',
        'inspection_snapshot_sha256',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'stage' => Stage::class,
            'stage_changed_at' => 'datetime',
            'entry_date' => DateOnly::class,
            'expected_date' => DateOnly::class,
            'delivered_at' => 'datetime',
            'km_in' => 'integer',
            'km_out' => 'integer',
            'test_drive_authorized' => 'boolean',
            'diagnosis_charged' => 'boolean',
            'diagnosis_fee_cents' => 'integer',
            'has_wheel_work' => 'boolean',
            'requires_test_drive' => 'boolean',
            'cancelled_at' => 'datetime',
            'has_spare_tire' => 'boolean',
            'has_jack' => 'boolean',
            'has_triangle' => 'boolean',
            'has_wheel_wrench' => 'boolean',
            'no_visible_damage' => 'boolean',
            'inspection_sent_at' => 'datetime',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(StageTransition::class)->orderBy('id');
    }

    /** Fotos e vídeos em uso (as refeitas ficam guardadas fora desta lista). */
    public function media(): HasMany
    {
        return $this->hasMany(Media::class)->whereNull('replaced_at')->orderBy('id');
    }

    public function damages(): HasMany
    {
        return $this->hasMany(Damage::class)->orderBy('id');
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(OrderChecklistItem::class)->orderBy('position')->orderBy('id');
    }

    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class)->orderBy('id');
    }

    /** Pasta dos arquivos desta OS no disco privado. */
    public function storageDir(): string
    {
        return 'os/'.($this->unit?->code ?? 'U'.$this->unit_id).'-'.$this->number;
    }

    /** "OS 1190": o número como a oficina fala (sem zeros à esquerda, igual ao sistema atual). */
    public function label(): string
    {
        return 'OS '.$this->number;
    }

    /** Ainda está na oficina (não entregue nem cancelada). */
    public function isOpen(): bool
    {
        return ! $this->stage->isFinal();
    }

    /** Dias inteiros na etapa atual (para o quadro mostrar o que está parado). */
    public function daysInStage(): int
    {
        $since = $this->stage_changed_at ?? $this->created_at;

        return $since ? (int) $since->copy()->startOfDay()->diffInDays(now()->startOfDay()) : 0;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('stage', Stage::openValues());
    }

    public function scopeInStage(Builder $query, Stage ...$stages): Builder
    {
        return $query->whereIn('stage', array_map(fn (Stage $stage) => $stage->value, $stages));
    }

    /** Busca por número, placa (qualquer padrão), modelo, cliente, telefone ou responsável. */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        $plate = LicensePlate::normalize($term);
        $digits = (string) preg_replace('/\D/', '', $term);

        return $query->where(function (Builder $q) use ($term, $plate, $digits) {
            if ($digits !== '' && $digits === ltrim($term, 'OSos #')) {
                $q->orWhere('number', (int) $digits);
            }

            $q->orWhereHas('vehicle', function (Builder $v) use ($term, $plate) {
                $v->where('brand_model', 'like', '%'.$term.'%');

                if ($plate !== '') {
                    $v->orWhere('plate_key', 'like', '%'.$plate.'%')->orWhere('plate', 'like', '%'.LicensePlate::clean($term).'%');
                }
            });

            $q->orWhereHas('customer', fn (Builder $c) => $c->search($term));
            $q->orWhereHas('responsible', fn (Builder $u) => $u->where('short_name', 'like', '%'.$term.'%'));
        });
    }
}
