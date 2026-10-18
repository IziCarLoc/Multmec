<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Teste de PDF · Multmec OS</title>
    <style>
        @page { margin: 14mm 12mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #141a1f; }
        h1 { font-size: 14pt; margin: 0 0 2mm; }
        p { margin: 0 0 4mm; color: #55626d; }
        table { width: 100%; border-collapse: collapse; }
        td { width: 50%; padding: 1.5mm; vertical-align: top; }
        img { width: 88mm; height: auto; border: 0.3mm solid #cddae4; }
    </style>
</head>
<body>
    <h1>Teste de PDF com {{ count($photos) }} fotos</h1>
    <p>Gerado em {{ $generatedAt->format('d/m/Y H:i:s') }}. Se este arquivo abriu, a hospedagem aguenta o PDF da OS.</p>
    <table>
        @foreach (array_chunk($photos, 2) as $pair)
            <tr>
                @foreach ($pair as $photo)
                    <td><img src="{{ $photo }}" alt=""></td>
                @endforeach
            </tr>
        @endforeach
    </table>
</body>
</html>
