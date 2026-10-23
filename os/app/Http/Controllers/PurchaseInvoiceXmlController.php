<?php

namespace App\Http\Controllers;

use App\Models\PurchaseInvoice;
use Symfony\Component\HttpFoundation\Response;

/**
 * O XML original da nota de compra (a prova da nota, para guardar ou mandar ao contador), igual ao que entrou.
 * Só com login e perfil do Financeiro (a rota fica no grupo `can:atendimento`).
 */
class PurchaseInvoiceXmlController extends Controller
{
    public function show(PurchaseInvoice $invoice): Response
    {
        $xml = $invoice->originalXml();
        abort_if($xml === null, 404, 'Esta nota não tem XML guardado.');
        $name = 'NFe'.preg_replace('/[^0-9A-Za-z]/', '', (string) ($invoice->access_key ?? $invoice->number)).'.xml';

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }
}
