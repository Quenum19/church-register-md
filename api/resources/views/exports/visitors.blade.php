{{--
    Export PDF des visiteurs (dompdf, A4 paysage) — App\Exports\PdfVisitorExport.
    SÉCURITÉ : toutes les données sont affichées avec la syntaxe échappée de Blade. Ne jamais
    utiliser la syntaxe non échappée dans ce fichier (XSS stockée via un nom ou une commune).
    PERFORMANCE : les lignes arrivent par tableaux de PdfVisitorExport::ROWS_PER_TABLE ($tables) ;
    dompdf met en page beaucoup plus vite plusieurs petits tableaux qu'un seul grand. Éviter ici
    les sélecteurs coûteux (:nth-child) et page-break-inside sur les lignes.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Visiteurs — {{ $churchName }}</title>
    <style>
        @page { margin: 12mm 10mm 14mm 10mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 7pt; color: #1f2937; }
        h1 { font-size: 12pt; margin: 0 0 1.5mm 0; color: #3b0764; }
        p { margin: 0 0 1mm 0; color: #4b5563; }
        .filters { margin-bottom: 3mm; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; }
        th { background-color: #ede9fe; color: #3b0764; font-weight: bold; }
        th, td { text-align: left; vertical-align: top; padding: 1mm 1.2mm; border-bottom: 0.5pt solid #d1d5db; }
        td.num { text-align: right; }
        .empty { padding: 6mm 0; text-align: center; }
        footer { position: fixed; bottom: -9mm; left: 0; right: 0; font-size: 6.5pt; color: #6b7280; }
        footer .page:after { content: counter(page); }
    </style>
</head>
<body>
    <footer>
        {{ $churchName }} — export du {{ $generatedAt->format('d/m/Y à H:i') }} — page <span class="page"></span>
    </footer>

    <h1>Visiteurs — {{ $churchName }}</h1>
    <p>Export du {{ $generatedAt->format('d/m/Y à H:i') }} — {{ $count }} visiteur{{ $count > 1 ? 's' : '' }}</p>
    <p class="filters">
        @if (count($filters) > 0)
            Filtres : {{ implode(' · ', $filters) }}
        @else
            Aucun filtre : tous les visiteurs.
        @endif
    </p>

    @if ($count === 0)
        <p class="empty">Aucun visiteur ne correspond aux filtres.</p>
    @endif

    @foreach ($tables as $rows)
        <table>
            <thead>
                <tr>
                    @foreach ($headings as $heading)
                        <th>{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach ($row as $index => $value)
                            <td @class(['num' => $index === $numericColumn])>{{ $value }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach
</body>
</html>
