@php
    use App\Models\Budget;
    use App\Services\Budgets\Budgets;
    use App\Support\Money;
    use Carbon\CarbonImmutable;
    $title = $s['tipo'] === Budget::KIND_ADDENDUM ? 'ADENDO '.$s['numero'] : 'ORÇAMENTO';
    $parts = array_values(array_filter($s['itens'], fn ($i) => $i['tipo'] === 'peca'));
    $services = array_values(array_filter($s['itens'], fn ($i) => $i['tipo'] === 'servico'));
    $approvedIds = $consent && $budget?->status === Budget::STATUS_APPROVED ? ($consent->snapshot['resposta']['itens_aprovados'] ?? []) : null;
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} · {{ $s['os'] }}</title>
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
        .items th { text-align: left; font-size: 7.5pt; color: #55626d; border-bottom: 0.3mm solid #cddae4; padding: 1mm 1.5mm; }
        .items td { padding: 1.2mm 1.5mm; border-bottom: 0.2mm solid #e3e9ee; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .tag { font-size: 7pt; color: #9a6413; }
        .refused td { color: #9aa5ae; text-decoration: line-through; }
        .totals td { padding: 1mm 1.5mm; }
        .totals .grand td { font-size: 11pt; font-weight: bold; border-top: 0.4mm solid #1d2328; }
        .hash { font-family: 'DejaVu Sans Mono', monospace; font-size: 6pt; color: #6b7883; word-break: break-all; }
        .legal { white-space: pre-line; font-size: 7.5pt; border: 0.3mm solid #cddae4; padding: 2mm; color: #33404a; }
        .notice { border: 0.4mm solid #9a6413; background: #fdf6e7; padding: 2mm; font-size: 8pt; }
        .preview { position: fixed; top: 90mm; left: 0; right: 0; text-align: center; font-size: 60pt; color: #e3e9ee; transform: rotate(-30deg); }
        .sign img { height: 22mm; }
        .keep { page-break-inside: avoid; }
    </style>
</head>
<body>
@if ($preview)
    <div class="preview">PRÉVIA</div>
@endif
<header>
    <table>
        <tr>
            <td style="width: 19mm">@if ($logo)<img class="logo" src="{{ $logo }}" alt="">@endif</td>
            <td>
                <div class="brand">{{ $s['oficina']['nome'] }}</div>
                <div class="muted">{{ $s['oficina']['cnpj'] ? 'CNPJ '.$s['oficina']['cnpj'].' · ' : '' }}{{ $s['oficina']['endereco'] }}{{ $s['oficina']['telefone'] ? ' · '.$s['oficina']['telefone'] : '' }}</div>
            </td>
            <td style="width: 46mm"><div class="doc">{{ $title }}<strong>{{ $s['os'] }}</strong></div></td>
        </tr>
    </table>
</header>
<footer>
    {{ $s['os'] }} · {{ $s['veiculo']['placa'] }} · {{ $preview ? 'prévia, não enviada' : 'congelado em '.$budget->frozen_at?->format('d/m/Y H:i') }} · gerado em {{ $generatedAt->format('d/m/Y H:i:s') }} (hora do servidor) · SHA-256 {{ substr($hash, 0, 16) }}
</footer>

<h2>Veículo e cliente</h2>
<table class="data">
    <tr><td class="k">Cliente</td><td>{{ $s['cliente'] }}</td><td class="k">Placa</td><td>{{ $s['veiculo']['placa'] }}</td></tr>
    <tr><td class="k">Veículo</td><td>{{ $s['veiculo']['descricao'] ?: 'não informado' }}</td><td class="k">Km na entrada</td><td>{{ $s['veiculo']['km'] ? number_format($s['veiculo']['km'], 0, ',', '.') : '—' }}</td></tr>
    @if ($s['diagnostico'])
        <tr><td class="k">Diagnóstico</td><td colspan="3">{{ $s['diagnostico'] }}</td></tr>
    @endif
</table>

@foreach (['Peças' => $parts, 'Serviços (mão de obra)' => $services] as $heading => $rows)
    @if ($rows !== [])
        <h2>{{ $heading }}</h2>
        <table class="items">
            <tr>
                <th>Descrição</th>
                @if ($heading === 'Peças')<th>Tipo</th>@endif
                <th class="num">Qtd</th>
                <th class="num">Unitário</th>
                <th class="num">Total</th>
            </tr>
            @foreach ($rows as $row)
                <tr @class(['refused' => $approvedIds !== null && ! in_array($row['id'], $approvedIds, true)])>
                    <td>
                        {{ $row['descricao'] }}
                        @if ($row['marca']) <span class="muted">· {{ $row['marca'] }}</span> @endif
                        @if ($row['prioridade'] === 'recomendado') <span class="tag">· recomendado</span> @endif
                        @if ($row['prazo']) <span class="muted">· prazo {{ $row['prazo'] }}</span> @endif
                    </td>
                    @if ($heading === 'Peças')<td>{{ $row['origem'] ?? '—' }}</td>@endif
                    <td class="num">{{ Budgets::quantity($row['quantidade']) }}</td>
                    <td class="num">{{ Money::brl($row['unitario']) }}</td>
                    <td class="num">{{ Money::brl($row['total']) }}</td>
                </tr>
            @endforeach
        </table>
    @endif
@endforeach

<table class="totals" style="margin-top: 3mm; width: 70mm; margin-left: auto;">
    <tr><td>Peças</td><td class="num">{{ Money::brl($s['pecas']) }}</td></tr>
    <tr><td>Mão de obra</td><td class="num">{{ Money::brl($s['mao_de_obra']) }}</td></tr>
    <tr class="grand"><td>Total</td><td class="num">{{ Money::brl($s['total']) }}</td></tr>
    @if ($approvedIds !== null && $budget->approved_total_cents !== $budget->total_cents)
        <tr><td><strong>Aprovado</strong></td><td class="num"><strong>{{ Money::brl($budget->approved_total_cents) }}</strong></td></tr>
    @endif
</table>

<h2>Condições</h2>
<table class="data">
    <tr><td class="k">Pagamento</td><td>{{ $s['pagamento'] ?: 'a combinar' }}</td></tr>
    <tr><td class="k">Previsão de entrega</td><td>{{ $s['previsao'] ? CarbonImmutable::parse($s['previsao'])->format('d/m/Y') : 'a combinar' }}</td></tr>
    <tr><td class="k">Validade</td><td>{{ $budget?->valid_until ? 'até '.$budget->valid_until->format('d/m/Y') : $s['validade_dias'].' dias a partir do recebimento' }}</td></tr>
    @if ($s['observacoes'])
        <tr><td class="k">Observações</td><td>{{ $s['observacoes'] }}</td></tr>
    @endif
</table>

@if ($s['tem_nao_original'])
    <p class="notice" style="margin-top: 3mm;">Este orçamento tem peça não original (paralela ou usada). Ela só é aplicada com a autorização do cliente{{ $budget?->non_original_authorized ? ', que foi dada no aceite' : '' }}.</p>
@endif

<div class="keep">
    <h2>Garantia</h2>
    <div class="legal">{{ $s['garantia'] }}</div>
</div>

<div class="keep">
    <h2>Autorização</h2>
    <div class="legal">{{ $s['texto_aceite'] }}</div>
</div>

@if ($budget && $budget->status !== Budget::STATUS_SENT && $consent)
    <h2>{{ $budget->status === Budget::STATUS_APPROVED ? 'Aceite' : 'Recusa' }}</h2>
    <table class="data">
        <tr><td class="k">Como</td><td>{{ Budget::METHODS[$budget->decision_method] ?? $budget->decision_method }}</td><td class="k">Quando</td><td>{{ $budget->decided_at?->format('d/m/Y H:i') }} (hora do servidor)</td></tr>
        <tr><td class="k">Quem</td><td>{{ $consent->signer_name }}{{ $consent->maskedDocument() ? ' · '.$consent->maskedDocument() : '' }}</td><td class="k">Registrado por</td><td>{{ $budget->decider?->short_name ?? 'o próprio cliente, pelo link' }}</td></tr>
        @if ($consent->message_text)
            <tr><td class="k">Mensagem</td><td colspan="3">{{ $consent->message_text }}</td></tr>
        @endif
        @if ($budget->refusal_reason)
            <tr><td class="k">Motivo</td><td colspan="3">{{ $budget->refusal_reason }}</td></tr>
        @endif
        @if ($consent->ip)
            <tr><td class="k">Aparelho</td><td colspan="3">IP {{ $consent->ip }} · {{ \Illuminate\Support\Str::limit((string) $consent->user_agent, 90) }}</td></tr>
        @endif
    </table>
    @if ($signatureImage)
        <p class="sign"><img src="{{ $signatureImage }}" alt=""></p>
    @endif
    <p class="hash">Resposta: SHA-256 {{ $consent->snapshot_sha256 }}</p>
@endif

<p class="hash" style="margin-top: 4mm;">Orçamento: SHA-256 {{ $hash }}</p>
</body>
</html>
