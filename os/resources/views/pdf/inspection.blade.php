@php
    use App\Services\Inspections\EntryInspection;
    $vehicle = $order->vehicle;
    $customer = $order->customer;
    $yesNo = fn ($value) => $value === null ? 'não informado' : ($value ? 'sim' : 'não');
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Vistoria de entrada · {{ $order->label() }}</title>
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
        h2 { font-size: 10pt; margin: 5mm 0 2mm; padding-bottom: 1mm; border-bottom: 0.3mm solid #0874bd; color: #0874bd; text-transform: uppercase; letter-spacing: 0.4pt; }
        table { width: 100%; border-collapse: collapse; }
        .data td { padding: 1mm 1.5mm; border-bottom: 0.2mm solid #e3e9ee; vertical-align: top; }
        .data td.k { width: 32mm; color: #55626d; }
        .photos td { width: 50%; padding: 1.5mm; vertical-align: top; }
        .photos img { width: 88mm; height: auto; border: 0.3mm solid #cddae4; }
        .caption { font-size: 7.5pt; color: #55626d; margin-top: 0.8mm; }
        .hash { font-family: 'DejaVu Sans Mono', monospace; font-size: 6pt; color: #6b7883; word-break: break-all; }
        .check td { padding: 0.8mm 1.5mm; border-bottom: 0.2mm solid #e3e9ee; }
        .bad { color: #b3261e; font-weight: bold; }
        .warn { color: #9a6413; font-weight: bold; }
        .ok { color: #2d7a4b; }
        .legal { white-space: pre-line; font-size: 7.5pt; border: 0.3mm solid #cddae4; padding: 2mm; color: #33404a; }
        .sign img { height: 22mm; }
        .page-break { page-break-before: always; }
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
            <td style="width: 46mm"><div class="doc">VISTORIA DE ENTRADA<strong>{{ $order->label() }}</strong></div></td>
        </tr>
    </table>
</header>
<footer>
    {{ $order->label() }} · {{ $vehicle->plateFormatted() }} · gerado em {{ $generatedAt->format('d/m/Y H:i:s') }} (hora do servidor) · resumo dos dados {{ substr($snapshotHash, 0, 16) }}
</footer>

<h2>Veículo e cliente</h2>
<table class="data">
    <tr><td class="k">Placa</td><td>{{ $vehicle->plateFormatted() }}</td><td class="k">Veículo</td><td>{{ $vehicle->description() ?: 'não informado' }}</td></tr>
    <tr><td class="k">Cliente</td><td>{{ $customer->name }}</td><td class="k">Entrada</td><td>{{ $order->entry_date?->format('d/m/Y') }}</td></tr>
    <tr><td class="k">Queixa do cliente</td><td colspan="3">{{ $order->complaint }}</td></tr>
    @if ($vehicle->is_izi_fleet)
        <tr><td class="k">Frota</td><td colspan="3">Izi Car{{ $vehicle->investor_name ? ' · investidor '.$vehicle->investor_name : '' }}</td></tr>
    @endif
</table>

<h2>Painel e itens do carro</h2>
<table class="data">
    <tr><td class="k">Km</td><td>{{ $order->km_in !== null ? number_format($order->km_in, 0, ',', '.') : 'não informado' }}</td><td class="k">Combustível</td><td>{{ EntryInspection::FUEL[$order->fuel_in] ?? 'não informado' }}</td></tr>
    <tr><td class="k">Luzes no painel</td><td colspan="3">{{ $order->dashboard_lights ?: 'não informado' }}</td></tr>
    <tr><td class="k">Objetos e acessórios</td><td colspan="3">{{ $order->belongings ?: 'não informado' }}</td></tr>
    <tr>
        <td class="k">No carro</td>
        <td colspan="3">
            @foreach (EntryInspection::ACCESSORIES as $field => $label)
                {{ $label }}: <strong>{{ $yesNo($order->{$field}) }}</strong>@if (! $loop->last) · @endif
            @endforeach
        </td>
    </tr>
    <tr><td class="k">Test drive</td><td>{{ $yesNo($order->test_drive_authorized) }}{{ $order->test_drive_authorized ? ' (autorizado)' : '' }}</td><td class="k">Diagnóstico cobrado</td><td>{{ $yesNo($order->diagnosis_charged) }}{{ $order->diagnosis_charged && $order->diagnosis_fee_cents ? ' · '.\App\Support\Money::brl($order->diagnosis_fee_cents) : '' }}</td></tr>
    <tr><td class="k">Quem aprova</td><td colspan="3">{{ \App\Services\Inspections\EntryInspection::APPROVERS[$order->approver_kind] ?? 'não informado' }}{{ $order->approver_name ? ' · '.$order->approver_name : '' }}</td></tr>
</table>

<h2>Fotos da entrada</h2>
<table class="photos">
    @foreach (array_chunk($photos, 2) as $pair)
        <tr>
            @foreach ($pair as $photo)
                <td>
                    @if ($photo['image'])<img src="{{ $photo['image'] }}" alt="">@endif
                    <div class="caption">{{ EntryInspection::PHOTOS[$photo['media']->position] ?? $photo['media']->kind->label() }} · recebida {{ $photo['media']->received_at->format('d/m/Y H:i:s') }}{{ $photo['media']->is_stale ? ' · foto antiga (tirada '.$photo['media']->taken_at?->format('d/m H:i').')' : '' }}</div>
                    <div class="hash">SHA-256 {{ $photo['media']->stamped_sha256 }}</div>
                </td>
            @endforeach
        </tr>
    @endforeach
</table>

<h2>Avarias</h2>
@if ($damages === [])
    <p>{{ $order->no_visible_damage ? 'Veículo sem avarias aparentes na entrada.' : 'Avarias não registradas.' }}</p>
@else
    <table class="photos">
        @foreach (array_chunk($damages, 2) as $pair)
            <tr>
                @foreach ($pair as $item)
                    <td>
                        @if ($item['image'])<img src="{{ $item['image'] }}" alt="">@endif
                        <div class="caption"><strong>{{ $item['damage']->location }}</strong></div>
                        @foreach ($item['damage']->markList() as $i => $mark)
                            <div class="caption">{{ $item['first'] + $i }}. {{ $mark['type'] }}{{ $mark['desc'] ? ': '.$mark['desc'] : '' }}</div>
                        @endforeach
                        <div class="hash">SHA-256 {{ $item['damage']->media?->stamped_sha256 }}</div>
                    </td>
                @endforeach
            </tr>
        @endforeach
    </table>
@endif

@if ($checklist->isNotEmpty())
    <h2>Checklist técnico</h2>
    <table class="check">
        @foreach ($checklist->groupBy('section') as $section => $items)
            <tr><td colspan="2"><strong>{{ $section }}</strong></td></tr>
            @foreach ($items as $item)
                <tr>
                    <td style="width: 70mm">{{ $item->label }}</td>
                    <td>
                        @if ($item->state === null)
                            <span class="muted">não conferido</span>
                        @else
                            <span @class(['bad' => $item->isNonConformity(), 'warn' => $item->state === 'media', 'ok' => in_array($item->state, ['ok', 'ideal'], true)])>{{ $item->stateLabel() }}</span>{{ $item->detail ? ' · '.$item->detail : '' }}
                        @endif
                    </td>
                </tr>
            @endforeach
        @endforeach
    </table>
@endif

<h2>Entrada</h2>
<div class="legal">{{ $legalText }}</div>

@if ($signature)
    <table class="data sign" style="margin-top: 3mm">
        <tr>
            <td style="width: 60mm">@if ($signatureImage)<img src="{{ $signatureImage }}" alt="">@endif</td>
            <td>
                Assinado na tela por <strong>{{ $signature->signer_name }}</strong>{{ $signature->maskedDocument() ? ' · '.$signature->maskedDocument() : '' }}<br>
                em {{ $signature->signed_at->format('d/m/Y H:i:s') }} (hora do servidor){{ $signature->ip ? ' · IP '.$signature->ip : '' }}<br>
                <span class="hash">assinatura SHA-256 {{ $signature->signature_sha256 }} · dados SHA-256 {{ $signature->snapshot_sha256 }}</span>
            </td>
        </tr>
    </table>
@elseif ($sentAt)
    <p style="margin-top: 3mm">Vistoria enviada ao cliente pelo WhatsApp em {{ $sentAt->format('d/m/Y H:i:s') }} (hora do servidor).</p>
@else
    <p style="margin-top: 3mm" class="muted">Vistoria ainda não enviada nem assinada.</p>
@endif

<p class="muted" style="margin-top: 3mm">Recebido por {{ $order->receiver?->short_name ?? 'atendimento' }}. As fotos têm a data e a hora do servidor gravadas na imagem; o SHA-256 de cada uma permite conferir que o arquivo não foi alterado.</p>
</body>
</html>
