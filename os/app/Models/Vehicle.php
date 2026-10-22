<?php

namespace App\Models;

use App\Support\LicensePlate;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Veículo. A placa é a chave do dia a dia: `plate` é como foi digitada; `plate_key` é a forma Mercosul
 * (ABC1234 e ABC1C34 são o mesmo carro), única. Use `Vehicle::byPlate()` para achar.
 */
class Vehicle extends Model
{
    protected $fillable = [
        'plate',
        'brand_model',
        'year',
        'color',
        'chassis',
        'engine',
        'customer_id',
        'is_izi_fleet',
        'investor_name',
        'investor_whatsapp',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_izi_fleet' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Vehicle $vehicle) {
            $vehicle->plate = LicensePlate::clean($vehicle->plate);
            $vehicle->plate_key = LicensePlate::normalize($vehicle->plate);
        });
    }

    public static function byPlate(?string $plate): ?self
    {
        $key = LicensePlate::normalize($plate);

        return $key === '' ? null : self::query()->where('plate_key', $key)->first();
    }

    /** Placa antiga (ABC1234) ou Mercosul (ABC1D23). */
    public static function isValidPlate(?string $plate): bool
    {
        return preg_match('/^[A-Z]{3}\d[A-Z0-9]\d{2}$/', LicensePlate::clean($plate)) === 1;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function serviceOrders(): HasMany
    {
        return $this->hasMany(ServiceOrder::class);
    }

    /** "ABC-1D23": a placa como se lê na rua. */
    public function plateFormatted(): string
    {
        return strlen($this->plate) === 7 ? substr($this->plate, 0, 3).'-'.substr($this->plate, 3) : $this->plate;
    }

    public function investorWhatsappFormatted(): string
    {
        return Phone::format($this->investor_whatsapp);
    }

    /** "Onix 1.0 · branco · 2020": o que aparece no cartão. */
    public function description(): string
    {
        return collect([$this->brand_model, $this->color, $this->year])->filter()->implode(' · ');
    }

    /** Busca por placa (qualquer padrão), modelo ou investidor. */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);
        $plate = LicensePlate::normalize($term);

        return $query->where(function (Builder $q) use ($term, $plate) {
            $q->where('brand_model', 'like', '%'.$term.'%')
                ->orWhere('investor_name', 'like', '%'.$term.'%');

            if ($plate !== '') {
                $q->orWhere('plate_key', 'like', '%'.$plate.'%')->orWhere('plate', 'like', '%'.LicensePlate::clean($term).'%');
            }
        });
    }
}
