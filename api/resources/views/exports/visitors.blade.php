{{--
    Export PDF des visiteurs (dompdf, A4 paysage) — App\Exports\PdfVisitorExport.

    SÉCURITÉ : toutes les données sont affichées avec la syntaxe échappée de Blade. Ne jamais
    utiliser la syntaxe non échappée dans ce fichier (XSS stockée via un nom ou une commune).
    Le logo arrive en data URI base64 ($logo) : dompdf tourne sans ressource distante.
    Le texte recherché n'est JAMAIS transmis à cette vue (voir VisitorExport::describeFilters()).

    MISE EN PAGE : sept colonnes seulement (PdfVisitorExport::COLUMNS). Les champs secondaires
    sont imprimés en gris sous leur champ principal — l'origine sous le nom, le second numéro
    WhatsApp sous le téléphone, la famille d'accueil sous le statut : le document se lit, là où
    treize colonnes serrées se déchiffraient. Numéros et dates sont en `white-space: nowrap`
    pour ne jamais se couper en deux lignes.

    PERFORMANCE : les lignes arrivent par tableaux de PdfVisitorExport::ROWS_PER_TABLE ($tables) ;
    dompdf met en page beaucoup plus vite plusieurs petits tableaux qu'un seul grand. Éviter ici
    les sélecteurs coûteux (:nth-child), les dégradés, les ombres et page-break-inside sur les
    lignes. Les largeurs viennent de $columns (table-layout: fixed : aucun calcul de contenu).

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

        body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #1F2937; }

        table { border-collapse: separate; border-spacing: 0; }

        /* En-tête de la 1re page : logo à gauche, identité à droite, filet or en dessous. */
        table.brand { width: 100%; background-color: #F3E8FF;
                      border-bottom: 1.4pt solid #C9A227; }
        table.brand td { padding: 3.5mm 4mm; vertical-align: middle; }
        td.mark { width: 26mm; }
        td.mark img { width: 19mm; }
        td.ident { text-align: right; }
        .church { font-size: 15pt; font-weight: bold; color: #4A0E6B; }
        .subtitle { font-size: 9.5pt; color: #7A5F0C; padding-top: 0.8mm; }

        /* Bandeau de contexte : titre et filtres à gauche, effectif à droite. */
        table.meta { width: 100%; margin: 5mm 0 4mm 0; }
        table.meta td { vertical-align: bottom; padding: 0; }
        h1 { font-size: 13pt; font-weight: bold; color: #4A0E6B; margin: 0 0 1.8mm 0; }
        p { margin: 0 0 1.2mm 0; color: #4B5563; font-size: 8pt; }
        .label { color: #7A5F0C; font-weight: bold; padding-right: 1.4mm; }

        td.tally { width: 32mm; text-align: right; }
        .tally-figure { font-size: 20pt; font-weight: bold; color: #6B1F8A; line-height: 1; }
        .tally-label { font-size: 8pt; color: #7A5F0C; padding-top: 0.6mm; }

        /* Tableau : en-tête violet à texte blanc, lignes alternées très claires, filets fins. */
        table.list { width: 100%; table-layout: fixed; }
        thead { display: table-header-group; }
        table.list th { background-color: #4A0E6B; color: #FFFFFF; font-weight: bold;
                        text-align: left; vertical-align: bottom; padding: 2mm 2mm;
                        border-bottom: 1.2pt solid #C9A227; }
        table.list td { vertical-align: top; padding: 1.4mm 2mm; line-height: 1.2;
                        word-wrap: break-word; border-bottom: 0.4pt solid #E6DEEC; }
        table.list tr.alt td { background-color: #FAF6FD; }

        th.num, td.num { text-align: right; }
        td.num { font-size: 9.5pt; color: #4A0E6B; }

        .name { font-weight: bold; color: #1F2937; }
        .place { color: #1F2937; }
        .status { color: #4A0E6B; }

        /* Champs secondaires : même cellule, un cran plus bas dans la hiérarchie. */
        .sub { font-size: 7.2pt; color: #6B7280; padding-top: 0.3mm; }
        .nowrap { white-space: nowrap; }

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

    <table class="meta">
        <tr>
            <td>
                <h1>{{ $title }}</h1>
                <p><span class="label">Export du</span>{{ $generatedAt->format('d/m/Y à H:i') }}</p>
                <p>
                    <span class="label">Filtres :</span>                    @if (count($filters) > 0)
                        {{ implode(' · ', $filters) }}
                    @else
                        aucun, tous les visiteurs du registre.
                    @endif
                </p>
            </td>
            <td class="tally">
                <div class="tally-figure">{{ $count }}</div>
                <div class="tally-label">visiteur{{ $count > 1 ? 's' : '' }}</div>
            </td>
        </tr>
    </table>

    @if ($count === 0)
        <p class="empty">Aucun visiteur ne correspond aux filtres.</p>
    @else
        @foreach ($tables as $rows)
            <table class="list">
                {{-- Les largeurs sont portées par les cellules de la 1re ligne : en mise en page
                     fixe, dompdf ne lit QUE celles-là (Cellmap::add_frame) et ignore <colgroup>. --}}
                <thead>
                    <tr>
                        @foreach ($columns as $column)
                            <th style="width: {{ $column['width'] }}%" @class(['num' => $column['align'] === 'right'])>{{ $column['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $line => $row)
                        <tr @class(['alt' => $line % 2 === 1])>
                            <td>
                                <div class="name">{{ $row['name'] }}</div>
                                @if ($row['origin'] !== '')
                                    <div class="sub">{{ $row['origin'] }}</div>
                                @endif
                            </td>
                            <td>
                                <div class="nowrap">{{ $row['phone'] }}</div>
                                @if ($row['whatsapp'] !== '')
                                    <div class="sub nowrap">WhatsApp {{ $row['whatsapp'] }}</div>
                                @endif
                            </td>
                            <td>
                                <div class="place">{{ $row['commune'] }}</div>
                                @if ($row['quartier'] !== '')
                                    <div class="sub">{{ $row['quartier'] }}</div>
                                @endif
                            </td>
                            <td>
                                <div class="status">{{ $row['status'] }}</div>
                                @if ($row['family'] !== '')
                                    <div class="sub">Famille {{ $row['family'] }}</div>
                                @endif
                            </td>
                            <td class="num">{{ $row['visits'] }}</td>
                            <td class="nowrap">{{ $row['first'] }}</td>
                            <td class="nowrap">{{ $row['last'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach
    @endif
</body>
</html>
