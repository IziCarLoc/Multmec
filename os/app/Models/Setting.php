<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Um ajuste gravado pela tela (chave => valor em JSON). A leitura passa por App\Support\Settings,
 * que cai no padrão de config/oficina.php quando a chave não foi gravada.
 */
class Setting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
