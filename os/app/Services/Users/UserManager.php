<?php

namespace App\Services\Users;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Cadastro de quem usa o sistema. A senha inicial é aleatória e mostrada uma vez só (para o gestor
 * passar à pessoa); no primeiro acesso a pessoa cria a própria senha. Não há e-mail automático.
 */
class UserManager
{
    /**
     * @return array{user: User, password: string}
     */
    public function create(string $name, string $shortName, string $email, Role $role): array
    {
        $email = Str::lower(trim($email));
        $shortName = trim($shortName);

        $this->assertUnique($email, $shortName);

        $password = self::temporaryPassword();

        $user = DB::transaction(function () use ($name, $shortName, $email, $role, $password) {
            $user = User::create([
                'name' => trim($name),
                'short_name' => $shortName,
                'email' => $email,
                'role' => $role,
                'active' => true,
                'must_change_password' => true,
                'password' => Hash::make($password),
            ]);

            AuditLog::record($user, 'user.created', null, ['perfil' => $role->value, 'email' => $email]);

            return $user;
        });

        return ['user' => $user, 'password' => $password];
    }

    public function update(User $user, string $name, string $shortName, string $email, Role $role, bool $active): User
    {
        $email = Str::lower(trim($email));
        $shortName = trim($shortName);

        $this->assertUnique($email, $shortName, $user->id);

        if ($user->is(auth()->user()) && (! $active || $role !== Role::Gestor)) {
            throw new DomainException('Você não pode tirar o seu próprio perfil de gestor nem desativar o seu acesso.');
        }

        if ($user->isGestor() && (! $active || $role !== Role::Gestor) && User::query()->where('role', Role::Gestor->value)->where('active', true)->where('id', '!=', $user->id)->doesntExist()) {
            throw new DomainException('Precisa ficar pelo menos um gestor ativo.');
        }

        $before = $user->only(['name', 'short_name', 'email', 'role', 'active']);

        $user->fill([
            'name' => trim($name),
            'short_name' => $shortName,
            'email' => $email,
            'role' => $role,
            'active' => $active,
        ])->save();

        $changes = collect($user->getChanges())->except(['updated_at'])->map(fn ($value, $key) => ['antes' => $before[$key] instanceof Role ? $before[$key]->value : $before[$key], 'depois' => $value])->all();

        if ($changes !== []) {
            AuditLog::record($user, 'user.updated', null, $changes);
        }

        return $user;
    }

    /** Senha esquecida: o gestor gera uma provisória nova; a pessoa troca no próximo acesso. */
    public function resetPassword(User $user): string
    {
        $password = self::temporaryPassword();

        $user->forceFill([
            'password' => Hash::make($password),
            'must_change_password' => true,
            // O "manter conectado" dos aparelhos antigos deixa de valer.
            'remember_token' => Str::random(60),
        ])->save();

        AuditLog::record($user, 'user.password_reset');

        return $password;
    }

    /** 10 caracteres fáceis de ditar (sem 0/O, 1/l/I). */
    public static function temporaryPassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $password = '';

        for ($i = 0; $i < 10; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }

    private function assertUnique(string $email, string $shortName, ?int $ignoreId = null): void
    {
        $query = fn () => User::query()->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId));

        if ($query()->where('email', $email)->exists()) {
            throw new DomainException('Já existe um usuário com esse e-mail.');
        }

        if ($query()->where('short_name', $shortName)->exists()) {
            throw new DomainException('Já existe um usuário com esse nome curto. Use um sobrenome ou inicial ("Gabriel C.").');
        }
    }
}
