<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Um arquivo do DDA importado (de um CNPJ: a oficina, `scope` 0, ou uma empresa do grupo). */
class DdaImport extends Model
{
    protected $fillable = ['group_company_id', 'scope', 'file_name', 'format', 'generated_on', 'imported_on', 'titles_count', 'new_count', 'imported_by'];

    protected function casts(): array
    {
        return [
            'generated_on' => DateOnly::class,
            'imported_on' => DateOnly::class,
            'scope' => 'integer',
            'titles_count' => 'integer',
            'new_count' => 'integer',
        ];
    }

    public function groupCompany(): BelongsTo
    {
        return $this->belongsTo(GroupCompany::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
