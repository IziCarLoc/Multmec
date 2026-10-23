<?php

namespace App\Http\Controllers;

use App\Services\Orders\MonthlyReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Relatório mensal das OS em PDF paisagem (o mesmo da tela "Por mês"). */
class MonthlyReportController extends Controller
{
    public function show(Request $request, MonthlyReport $report): Response
    {
        $month = MonthlyReport::month($request->query('mes'));
        $mode = $request->query('por') === 'entrega' ? 'entrega' : 'entrada';
        $binary = Pdf::loadView('pdf.monthly', [
            'orders' => $report->orders($month, $mode),
            'month' => $month,
            'mode' => $mode,
            'generatedAt' => now(),
        ])->setPaper('a4', 'landscape')->output();

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="os-'.$month->format('Y-m').'-por-'.$mode.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
