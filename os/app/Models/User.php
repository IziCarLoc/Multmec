<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'short_name',
        'email',
        'role',
        'active',
        'must_change_password',
        'last_login_at',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'active' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isGestor(): bool
    {
        return $this->role === Role::Gestor;
    }

    public function isAtendimento(): bool
    {
        return $this->role === Role::Atendimento;
    }

    public function isMecanico(): bool
    {
        return $this->role === Role::Mecanico;
    }

    /** Tem um destes perfis (o gestor sempre passa: faz tudo). */
    public function hasRole(Role ...$roles): bool
    {
        return $this->isGestor() || in_array($this->role, $roles, true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /** Para os selects de "responsável": só quem está ativo, pelo nome curto. */
    public function scopeForPicker(Builder $query, Role ...$roles): Builder
    {
        return $query->active()
            ->when($roles !== [], fn (Builder $q) => $q->whereIn('role', array_map(fn (Role $r) => $r->value, $roles)))
            ->orderBy('short_name');
    }
}
