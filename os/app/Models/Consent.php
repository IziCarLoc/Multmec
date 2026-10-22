<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aceite ou assinatura: entrada, aceite e recusa do orçamento, liberação da conferência, retirada.
 * É "aceite eletrônico" (não "assinatura digital"): vale pelo conjunto do que fica gravado aqui (quem, como,
 * hora do servidor, IP, aparelho, texto legal da época e os SHA-256 da assinatura e dos dados assinados).
 */
class Consent extends Model
{
    public const PURPOSE_ENTRY = 'entrada';

    public const METHOD_SCREEN = 'assinatura_tela';

    protected $fillable = [
        'service_order_id',
        'purpose',
        'method',
        'signer_name',
        'signer_document',
        'signer_phone',
        'signer_relation',
        'message_text',
        'message_at',
        'ip',
        'user_agent',
        'signed_at',
        'signature_path',
        'signature_sha256',
        'snapshot',
        'snapshot_sha256',
        'legal_text',
        'legal_text_sha256',
        'pdf_path',
        'pdf_sha256',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'signed_at' => 'datetime',
            'message_at' => 'datetime',
        ];
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** CPF com o meio escondido nas telas e nos PDFs (***.456.789-**). */
    public function maskedDocument(): string
    {
        $digits = (string) $this->signer_document;

        return match (strlen($digits)) {
            11 => '***.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-**',
            14 => substr($digits, 0, 2).'.***.***/'.substr($digits, 8, 4).'-**',
            default => '',
        };
    }
}
