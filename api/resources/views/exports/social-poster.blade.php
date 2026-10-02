{{--
    Affichette « Suivez-nous » à poser sur les tables (dompdf, A5 portrait, une seule page) —
    App\Exports\SocialPosterExport. Le format A5 est celui des porte-affiches de l'Église.

    SÉCURITÉ : toutes les données sont affichées avec la syntaxe échappée de Blade. Ne jamais
    utiliser la syntaxe non échappée ici. Le logo et le QR code arrivent en data URI, dompdf
    tourne sans ressource distante.

    MISE EN PAGE : toutes les largeurs sont en pourcentage avec `table-layout: fixed` ; mélanger
    millimètres et pourcentages dépasse 100 % dans dompdf et comprime la ligne.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} — {{ $churchName }}</title>
    <style>
        @page { margin: 0; }

        body { font-family: "DejaVu Sans", sans-serif; color: #1F2937; margin: 0; }

        table { width: 100%; border-collapse: separate; border-spacing: 0; table-layout: fixed; }
        td { vertical-align: middle; text-align: center; }

        /* Liseré or en haut et en bas, comme l'affiche du QR code à l'écran. */
        .band { height: 6mm; background-color: #C9A227; }

        .sheet { height: 198mm; background-color: #FBF8FD; }
        .sheet td { padding: 14mm 12mm 0 12mm; }

        .logo img { width: 24mm; }
        .church { font-size: 16pt; font-weight: bold; color: #4A0E6B; padding-top: 3mm; }
        .title { font-size: 28pt; font-weight: bold; color: #6B1F8A; padding-top: 4mm; }
        .gold { width: 34mm; border-top: 1.8pt solid #C9A227; margin: 4mm auto 0 auto; }
        .subtitle { font-size: 11.5pt; color: #4B5563; padding-top: 4mm; }

        /* QR imprimé à 78 mm : lisible à bout de bras, au-dessus d'une table. */
        .qr { padding-top: 5mm; }
        .qr img { width: 78mm; }
        .url { font-size: 10pt; color: #6B1F8A; padding-top: 3mm; }

        .networks { font-size: 13pt; color: #4A0E6B; font-weight: bold; padding-top: 4mm; }
    </style>
</head>
<body>
    <div class="band"></div>

    <table class="sheet">
        <tr>
            <td>
                @if ($logo !== '')
                    <div class="logo"><img src="{{ $logo }}" alt=""></div>
                @endif
                <div class="church">{{ $churchName }}</div>
                <div class="title">{{ $title }}</div>
                <div class="gold"></div>
                <div class="subtitle">{{ $subtitle }}</div>

                @if ($qr !== '')
                    <div class="qr"><img src="{{ $qr }}" alt=""></div>
                @endif
                <div class="url">{{ $url }}</div>

                @if ($networks !== [])
                    <div class="networks">
                        {{ implode(' · ', array_map(static fn (array $network): string => $network['label'], $networks)) }}
                    </div>
                @endif
            </td>
        </tr>
    </table>

    <div class="band"></div>
</body>
</html>
