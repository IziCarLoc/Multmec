<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Um título que o banco mostrou no DDA (importar de novo o mesmo arquivo não duplica: `scope` + `title_key`). */
class DdaTitle extends Model
{
    protected $fillable = [
        'group_company_id', 'scope', 'title_key', 'barcode', 'bank_code', 'amount_cents', 'due_date', 'payee_cnpj', 'payee_name',
        'drawer_cnpj', 'drawer_name', 'payer_cnpj', 'document_number', 'issue_date', 'format', 'first_import_id', 'last_import_id',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => DateOnly::class,
            'issue_date' => DateOnly::class,
            'amount_cents' => 'integer',
            'scope' => 'integer',
        ];
    }

    public function groupCompany(): BelongsTo
    {
        return $this->belongsTo(GroupCompany::class);
    }

    public function lastImport(): BelongsTo
    {
        return $this->belongsTo(DdaImport::class, 'last_import_id');
    }
}
