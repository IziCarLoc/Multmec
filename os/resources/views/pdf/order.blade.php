@php
    use App\Models\Budget;
    use App\Enums\MediaKind;
    use App\Services\Budgets\Budgets;
    use App\Services\Delivery\Delivery;
    use App\Services\Execution\FinalCheck;
    use App\Services\Inspections\EntryInspection;
    use App\Support\Money;
    $vehicle = $order->vehicle;
    $customer = $order->customer;
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $order->label() }}</title>
    <style>
        @page { margin: 30mm 12mm 16mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5pt; color: #141a1f; }
        header { position: fixed; top: -24mm; left: 0; right: 0; height: 20mm; border-bottom: 0.8mm solid #d71920; }
        footer { position: fixed; bottom: -11mm; left: 0; right: 0; font-size: 7pt; color: #6b7883; border-top: 0.2mm solid #cddae4; padding-top: 1.5mm; }
        .logo { width: 16mm; height: 16mm; }
        .brand { font-size: 13pt; font-weight: bold; letter-spacing: 0.5pt; }
        .muted { color: #6b7883; }
        .doc { border: 0.4mm solid #1d2328; padding: 1.5mm 3mm; text-align: center; }
        .doc strong { display: block; font-size: 11pt; }
        h2 { page-break-after: avoid; font-size: 10pt; margin: 5mm 0 2mm; padding-bottom: 1mm; border-bottom: 0.3mm solid #0874bd; color: #0874bd; text-transform: uppercase; letter-spacing: 0.4pt; }
        table { width: 100%; border-collapse: collapse; }
        .data td { padding: 1mm 1.5mm; border-bottom: 0.2mm solid #e3e9ee; vertical-align: top; }
        .data td.k { width: 32mm; color: #55626d; }
        .items th { text-align: left; font-size: 7.5pt; color: #55626d; border-bottom: 0.3mm solid #cddae4; padding: 1mm 1.5mm; }
        .items td { padding: 1.2mm 1.5mm; border-bottom: 0.2mm solid #e3e9ee; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .photos td { width: 25%; padding: 1mm; vertical-align: top; }
        .photos img { width: 42mm; height: auto; border: 0.3mm solid #cddae4; }
        .pair td { width: 50%; padding: 1mm; vertical-align: top; }
        .pair img { width: 40mm; height: auto; border: 0.3mm solid #cddae4; }
        .caption { font-size: 7pt; color: #55626d; }
        .hash { font-family: 'DejaVu Sans Mono', monospace; font-size: 6pt; color: #6b7883; word-break: break-all; }
        .legal { white-space: pre-line; font-size: 7.5pt; border: 0.3mm solid #cddae4; padding: 2mm; color: #33404a; }
        .bad { color: #b3261e; font-weight: bold; }
        .ok { color: #2d7a4b; }
        .keep { page-break-inside: avoid; }
        .sign img { height: 20mm; }
    </style>
</head>
<body>
<header>
    <table>
        <tr>
            <td style="width: 19mm">@if ($logo)<img class="logo" src="{{ $logo }}" alt="">@endif</td>
            <td>
                <div class="brand">{{ $unit->trade_name }}</div>
                <div class="muted">{{ $unit->cnpjFormatted() ? 'CNPJ '.$unit->cnpjFormatted().' · ' : '' }}{{ $unit->address }}{{ $unit->city ? ' · '.$unit->city : '' }}{{ $unit->phone ? ' · '.$unit->phone : '' }}</div>
            </td>
            <td style="width: 46mm"><div class="doc">ORDEM DE SERVIÇO<strong>{{ $order->label() }}</strong></div></td>
        </tr>
    </table>
</header>
<footer>
    {{ $order->label() }} · {{ $vehicle->plateFormatted() }} · {{ $order->stage->label() }} · gerado em {{ $generatedAt->format('d/m/Y H:i:s') }} (hora do servidor)
</footer>

<h2>Veículo e cliente</h2>
<table class="data">
    <tr><td class="k">Cliente</td><td>{{ $customer->name }}</td><td class="k">Placa</td><td>{{ $vehicle->plateFormatted() }}</td></tr>
    <tr><td class="k">Veículo</td><td>{{ $vehicle->description() ?: 'não informado' }}</td><td class="k">Entrada</td><td>{{ $order->entry_date?->format('d/m/Y') }}</td></tr>
    <tr><td class="k">Km entrada / saída</td><td>{{ $order->km_in ? number_format($order->km_in, 0, ',', '.') : '—' }} / {{ $order->km_out ? number_format($order->km_out, 0, ',', '.') : '—' }}</td><td class="k">Combustível</td><td>{{ EntryInspection::FUEL[$order->fuel_in] ?? '—' }} / {{ EntryInspection::FUEL[$order->fuel_out] ?? '—' }}</td></tr>
    <tr><td class="k">Queixa do cliente</td><td colspan="3">{{ $order->complaint }}</td></tr>
    @if ($order->diagnosis)
        <tr><td class="k">Diagnóstico</td><td colspan="3">{{ $order->diagnosis }}</td></tr>
    @endif
    <tr><td class="k">Responsável</td><td>{{ $order->responsible?->short_name ?? '—' }}</td><td class="k">Executado por</td><td>{{ $workers->implode(', ') ?: '—' }}</td></tr>
</table>

<h2>Serviços e peças aprovados</h2>
@if ($items->isEmpty())
    <p class="muted">Nenhum item aprovado{{ $amounts['total'] > 0 ? ' (orçamento recusado: diagnóstico '.Money::brl($amounts['total']).')' : '' }}.</p>
@else
    <table class="items">
        <tr><th>Descrição</th><th>Tipo</th><th class="num">Qtd</th><th class="num">Unitário</th><th class="num">Total</th></tr>
        @foreach ($items as $item)
            <tr>
                <td>{{ $item->description }}{{ $item->brand ? ' · '.$item->brand : '' }}</td>
                <td>{{ $item->isPart() ? ($item->origin?->shortLabel() ?? 'Peça') : 'Serviço' }}</td>
                <td class="num">{{ Budgets::quantity((float) $item->quantity) }}</td>
                <td class="num">{{ Money::brl((int) $item->price_cents) }}</td>
                <td class="num">{{ Money::brl((int) $item->lineTotalCents()) }}</td>
            </tr>
        @endforeach
        <tr><td colspan="4" class="num"><strong>Total</strong></td><td class="num"><strong>{{ Money::brl($amounts['total']) }}</strong></td></tr>
    </table>
@endif

@if ($budgets->isNotEmpty())
    <h2>Orçamento e aceites</h2>
    <table class="data">
        @foreach ($budgets as $budget)
            <tr>
                <td class="k">{{ $budget->title() }}</td>
                <td>
                    {{ Money::brl($budget->total_cents) }} · {{ $budget->statusLabel() }}
                    @if ($budget->decided_at) · {{ Budget::METHODS[$budget->decision_method] ?? $budget->decision_method }} em {{ $budget->decided_at->format('d/m/Y H:i') }}{{ $budget->consent ? ' por '.$budget->consent->signer_name : '' }} @endif
                    @if ($budget->refusal_reason) · motivo: {{ $budget->refusal_reason }} @endif
                    <div class="hash">SHA-256 {{ $budget->snapshot_sha256 }}</div>
                </td>
            </tr>
        @endforeach
    </table>
@endif

@php($withPhotos = $items->filter(fn ($i) => $i->isPart() && $i->media->isNotEmpty()))
@if ($withPhotos->isNotEmpty())
    <h2>Peças: velha × nova</h2>
    @foreach ($withPhotos as $part)
        @php($old = $part->media->where('kind', MediaKind::PecaVelha)->sortByDesc('id')->first())
        @php($new = $part->media->where('kind', MediaKind::PecaNova)->sortByDesc('id')->first())
        <table class="pair keep">
            <tr>
                <td>@if ($src = $image($old))<img src="{{ $src }}" alt=""><div class="caption">Velha · {{ $part->description }}</div>@endif</td>
                <td>@if ($src = $image($new))<img src="{{ $src }}" alt=""><div class="caption">Nova · {{ $part->description }}</div>@endif</td>
            </tr>
        </table>
    @endforeach
@endif

<h2>Execução e conferência</h2>
<table class="data">
    @if ($order->has_wheel_work)
        <tr><td class="k">Torque das rodas</td><td>{{ $order->torque_nm ? $order->torque_nm.' Nm' : '—' }} · torquímetro {{ $order->torque_wrench ?: '—' }}</td></tr>
    @endif
    @if ($seals->isNotEmpty())
        <tr><td class="k">Lacres</td><td>{{ $seals->pluck('position')->implode(', ') }}</td></tr>
    @endif
    <tr><td class="k">Conferência</td><td>{{ $order->final_check_template ? FinalCheck::TEMPLATES[$order->final_check_template] : '—' }}{{ $order->finalChecker ? ' · por '.$order->finalChecker->short_name : '' }}{{ $order->final_check_round > 1 ? ' · '.$order->final_check_round.'ª rodada' : '' }}</td></tr>
    @if ($order->test_drive_ended_at)
        <tr><td class="k">Test drive</td><td>{{ number_format($order->test_drive_km_before, 0, ',', '.') }} → {{ number_format($order->test_drive_km_after, 0, ',', '.') }} km · {{ $order->test_drive_started_at?->format('d/m H:i') }}–{{ $order->test_drive_ended_at->format('H:i') }} · {{ $order->testDriver?->short_name }} · {{ $order->test_drive_route }}</td></tr>
    @endif
</table>
@if ($finalItems->isNotEmpty())
    <table class="items" style="margin-top: 2mm;">
        @foreach ($finalItems as $check)
            <tr><td>{{ $check->section }} · {{ $check->label }}</td><td class="num {{ $check->state === 'corrigir' ? 'bad' : 'ok' }}">{{ FinalCheck::STATES[$check->state] ?? 'não conferido' }}</td></tr>
        @endforeach
    </table>
@endif
@if ($wheels->isNotEmpty())
    <table class="photos keep" style="margin-top: 2mm;">
        <tr>
            @foreach (FinalCheck::WHEELS as $key => $label)
                <td>@if ($src = $image($wheels[$key] ?? null))<img src="{{ $src }}" alt=""><div class="caption">{{ $label }}</div>@endif</td>
            @endforeach
        </tr>
    </table>
@endif
@if ($finalConsent)
    <p class="hash">Liberação da conferência: {{ $finalConsent->signer_name }} em {{ $finalConsent->signed_at->format('d/m/Y H:i') }} · SHA-256 {{ $finalConsent->snapshot_sha256 }}</p>
@endif

<h2>Entrada × saída</h2>
<table class="pair">
    @foreach (EntryInspection::PHOTOS as $position => $label)
        @if ($entry->has($position) || $exit->has($position))
            <tr class="keep">
                <td>@if ($src = $image($entry[$position] ?? null))<img src="{{ $src }}" alt="">@endif<div class="caption">Entrada · {{ $label }}</div></td>
                <td>@if ($src = $image($exit[$position] ?? null))<img src="{{ $src }}" alt="">@endif<div class="caption">Saída · {{ $label }}</div></td>
            </tr>
        @endif
    @endforeach
</table>

@if ($pickup)
    <div class="keep">
        <h2>Retirada</h2>
        <table class="data">
            <tr><td class="k">Retirado por</td><td>{{ $pickup->signer_name }} · {{ $pickup->maskedDocument() }}{{ $pickup->signer_relation === 'autorizado por escrito' ? ' (autorizado por escrito)' : '' }}</td></tr>
            <tr><td class="k">Quando</td><td>{{ $pickup->signed_at->format('d/m/Y H:i') }} (hora do servidor)</td></tr>
            @if ($order->replaced_parts)
                <tr><td class="k">Peças substituídas</td><td>{{ Delivery::REPLACED_PARTS[$order->replaced_parts] ?? $order->replaced_parts }}</td></tr>
            @endif
        </table>
        @if ($pickupSignature)
            <p class="sign"><img src="{{ $pickupSignature }}" alt=""></p>
        @endif
        <p class="hash">Termo de retirada: SHA-256 {{ $pickup->snapshot_sha256 }}</p>
    </div>
@endif

<div class="keep">
    <h2>Garantia</h2>
    <div class="legal">{{ $warranty }}</div>
</div>

<div class="keep">
    <h2>Privacidade</h2>
    <div class="legal">{{ $privacy }}</div>
</div>
</body>
</html>
