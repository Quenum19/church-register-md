{{--
    Export PDF des visiteurs (dompdf, A4 paysage) — App\Exports\PdfVisitorExport.

    SÉCURITÉ : toutes les données sont affichées avec la syntaxe échappée de Blade. Ne jamais
    utiliser la syntaxe non échappée dans ce fichier (XSS stockée via un nom ou une commune).
    Le logo arrive en data URI base64 ($logo) : dompdf tourne sans ressource distante.
    Le texte recherché n'est JAMAIS transmis à cette vue (voir VisitorExport::describeFilters()).

    PERFORMANCE : les lignes arrivent par tableaux de PdfVisitorExport::ROWS_PER_TABLE ($tables) ;
    dompdf met en page beaucoup plus vite plusieurs petits tableaux qu'un seul grand. Éviter ici
    les sélecteurs coûteux (:nth-child), les dégradés, les ombres et page-break-inside sur les
    lignes. Les largeurs viennent de $widths (table-layout: fixed : aucun calcul de contenu).

    Le pied de page (nom de l'église, mention de confidentialité, « Page X / Y ») est estampillé
    sur le canevas par PdfVisitorExport::footer() : dompdf ne sait pas rendre `counter(pages)`.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} — {{ $churchName }}</title>
    <style>
        @page { margin: 11mm 10mm 15mm 10mm; }

        body { font-family: "DejaVu Sans", sans-serif; font-size: 8pt; color: #1F2937; }

        /* En-tête de la 1re page : bandeau violet discret, logo à gauche, identité à droite,
           filet or en dessous. */
        table.brand { width: 100%; border-collapse: separate; border-spacing: 0;
                      background-color: #F3E8FF; border-bottom: 1.4pt solid #C9A227;
                      margin-bottom: 5mm; }
        table.brand td { padding: 3mm 4mm; vertical-align: middle; }
        td.mark { width: 26mm; }
        td.mark img { width: 19mm; }
        td.ident { text-align: right; }
        .church { font-size: 15pt; font-weight: bold; color: #4A0E6B; }
        .subtitle { font-size: 9.5pt; color: #7A5F0C; }

        h1 { font-size: 12.5pt; font-weight: bold; color: #4A0E6B; margin: 0 0 1.5mm 0; }
        p { margin: 0 0 1.2mm 0; color: #4B5563; }
        p.filters { margin-bottom: 4mm; }
        .label { color: #7A5F0C; font-weight: bold; }

        /* Tableau : en-tête violet à texte blanc, lignes alternées très claires, filets fins. */
        table.list { width: 100%; border-collapse: separate; border-spacing: 0;
                     table-layout: fixed; }
        thead { display: table-header-group; }
        table.list th { background-color: #6B1F8A; color: #FFFFFF; font-weight: bold;
                        text-align: left; vertical-align: bottom; padding: 1.6mm 1.1mm;
                        border-bottom: 1pt solid #C9A227; }
        table.list td { vertical-align: top; padding: 1.1mm 1.1mm; line-height: 1.18;
                        word-wrap: break-word; border-bottom: 0.4pt solid #E6DEEC; }
        table.list tr.alt td { background-color: #F7F1FB; }
        th.num, td.num { text-align: right; }

        .empty { margin-top: 8mm; padding: 9mm 6mm; text-align: center; font-size: 10.5pt;
                 color: #4A0E6B; background-color: #F3E8FF; border: 0.7pt solid #C9A227; }
    </style>
</head>
<body>
    <table class="brand">
        <tr>
            <td class="mark">
                @if ($logo !== '')
                    <img src="{{ $logo }}" alt="">
                @endif
            </td>
            <td class="ident">
                <div class="church">{{ $churchName }}</div>
                <div class="subtitle">{{ $subtitle }}</div>
            </td>
        </tr>
    </table>

    <h1>{{ $title }}</h1>

    <p>
        <span class="label">Export du</span> {{ $generatedAt->format('d/m/Y à H:i') }}
        &nbsp;·&nbsp;
        <span class="label">{{ $count }} visiteur{{ $count > 1 ? 's' : '' }}</span>
    </p>

    <p class="filters">
        <span class="label">Filtres :</span>
        @if (count($filters) > 0)
            {{ implode(' · ', $filters) }}
        @else
            aucun, tous les visiteurs du registre.
        @endif
    </p>

    @if ($count === 0)
        <p class="empty">Aucun visiteur ne correspond aux filtres.</p>
    @else
        @foreach ($tables as $rows)
            <table class="list">
                {{-- Les largeurs sont portées par les cellules de la 1re ligne : en mise en page
                     fixe, dompdf ne lit QUE celles-là (Cellmap::add_frame) et ignore <colgroup>. --}}
                <thead>
                    <tr>
                        @foreach ($headings as $index => $heading)
                            <th style="width: {{ $widths[$index] }}%" @class(['num' => $index === $numericColumn])>{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $line => $row)
                        <tr @class(['alt' => $line % 2 === 1])>
                            @foreach ($row as $index => $value)
                                <td @class(['num' => $index === $numericColumn])>{{ $value }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach
    @endif
</body>
</html>
