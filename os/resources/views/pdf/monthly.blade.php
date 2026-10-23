@php
    use App\Services\Orders\MonthlyReport;
    use App\Support\Money;
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>OS de {{ $month->format('m/Y') }}</title>
    <style>
        @page { margin: 12mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8pt; color: #141a1f; }
        h1 { font-size: 13pt; margin: 0 0 1mm; }
        .muted { color: #6b7883; }
        table { width: 100%; border-collapse: collapse; margin-top: 4mm; }
        th { text-align: left; font-size: 7pt; color: #55626d; border-bottom: 0.4mm solid #1d2328; padding: 1mm 1.5mm; }
        td { padding: 1mm 1.5mm; border-bottom: 0.2mm solid #e3e9ee; }
        .num { text-align: right; white-space: nowrap; }
        tfoot td { font-weight: bold; border-top: 0.4mm solid #1d2328; }
    </style>
</head>
<body>
<h1>Ordens de serviço · {{ $month->translatedFormat('F/Y') }}</h1>
<p class="muted">Por {{ mb_strtolower(MonthlyReport::MODES[$mode]) }} · {{ $orders->count() }} OS · gerado em {{ $generatedAt->format('d/m/Y H:i') }}</p>
<table>
    <thead><tr><th>OS</th><th>Entrada</th><th>Entrega</th><th>Placa</th><th>Veículo</th><th>Cliente</th><th>Etapa</th><th>Nota</th><th class="num">Aprovado</th></tr></thead>
    <tbody>
        @foreach ($orders as $order)
            <tr>
                <td>{{ $order->number }}</td>
                <td>{{ $order->entry_date?->format('d/m') }}</td>
                <td>{{ $order->delivered_at?->format('d/m') ?? '—' }}</td>
                <td>{{ $order->vehicle?->plateFormatted() }}</td>
                <td>{{ $order->vehicle?->brand_model }}</td>
                <td>{{ $order->customer?->name }}</td>
                <td>{{ $order->stage->label() }}</td>
                <td>{{ $order->invoice_status === 'emitida' ? 'emitida' : 'pendente' }}</td>
                <td class="num">{{ Money::brl((int) $order->approved_cents) }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot><tr><td colspan="8">Total</td><td class="num">{{ Money::brl((int) $orders->sum('approved_cents')) }}</td></tr></tfoot>
</table>
</body>
</html>
