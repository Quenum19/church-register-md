{{--
    Affichette « Suivez-nous » à poser sur les tables (dompdf, A4 portrait) —
    App\Exports\SocialPosterExport.

    SÉCURITÉ : toutes les données sont affichées avec la syntaxe échappée de Blade. Ne jamais
    utiliser la syntaxe non échappée ici. Le logo et le QR code arrivent en data URI, dompdf
    tourne sans ressource distante.

    MISE EN PAGE : deux cartes identiques par page A4, séparées par un trait de coupe. Toutes les
    largeurs sont en pourcentage avec `table-layout: fixed` : mélanger millimètres et pourcentages
    dépasse 100 % dans dompdf et comprime la ligne.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} — {{ $churchName }}</title>
    <style>
        @page { margin: 10mm; }

        body { font-family: "DejaVu Sans", sans-serif; color: #1F2937; }

        table { width: 100%; border-collapse: separate; border-spacing: 0; table-layout: fixed; }
        td { vertical-align: middle; }

        .card { height: 132mm; border: 1pt solid #D9C8E4; background-color: #FBF8FD; }
        .card td { padding: 0 10mm; text-align: center; }

        .cut { border-top: 0.8pt dashed #9CA3AF; }
        .cut-note { font-size: 7pt; color: #6B7280; text-align: center; padding: 1mm 0 2.5mm 0; }

        .logo img { width: 20mm; }
        .church { font-size: 15pt; font-weight: bold; color: #4A0E6B; padding-top: 2mm; }
        .title { font-size: 22pt; font-weight: bold; color: #6B1F8A; padding-top: 3mm;
                 letter-spacing: 0.3mm; }
        .gold { width: 28mm; border-top: 1.6pt solid #C9A227; margin: 3mm auto 0 auto; }
        .subtitle { font-size: 10.5pt; color: #4B5563; padding-top: 3mm; }

        .qr img { width: 46mm; }
        .url { font-size: 9.5pt; color: #6B1F8A; padding-top: 1.5mm; }

        .networks { font-size: 11pt; color: #4A0E6B; font-weight: bold; padding-top: 3mm; }
    </style>
</head>
<body>
    @for ($card = 1; $card <= 2; $card++)
        @if ($card > 1)
            <div class="cut"></div>
            <div class="cut-note">— découper ici —</div>
        @endif

        <table class="card">
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
    @endfor
</body>
</html>
