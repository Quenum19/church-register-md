{{--
    Fiche de présence papier d'un événement (dompdf, A4 portrait) — App\Exports\EventFormExport.

    SÉCURITÉ : toutes les données sont affichées avec la syntaxe échappée de Blade. Ne jamais
    utiliser la syntaxe non échappée ici (le nom d'un événement est saisi par un administrateur).
    Le logo et le QR code arrivent en data URI ($logo, $qr) : dompdf tourne sans ressource distante.

    MISE EN PAGE : une seule fiche par page, écrite à la main. Les lignes de saisie font 14 mm,
    les libellés sont au-dessus du trait et non à côté, pour laisser toute la largeur à l'écriture.

    TABLEAUX : toutes les largeurs sont en `table-layout: fixed`, exprimées en POURCENTAGE et
    sommant à 100 par ligne. Deux pièges de dompdf à ne pas réintroduire :
      - en mise en page automatique, une cellule vide est réduite à presque rien (les traits de
        saisie et les cases à cocher disparaissent) ;
      - mélanger une largeur en millimètres et des pourcentages dépasse 100 % et comprime toute
        la ligne (les libellés se coupaient en deux).
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} — {{ $eventName }}</title>
    <style>
        @page { margin: 12mm 15mm; }

        body { font-family: "DejaVu Sans", sans-serif; color: #1F2937; font-size: 11pt; }

        table { width: 100%; border-collapse: separate; border-spacing: 0; table-layout: fixed; }
        td { vertical-align: middle; }

        /* En-tête : logo, identité de l'Église, événement. */
        .head td { padding-bottom: 3mm; vertical-align: middle; }
        .head .mark img { width: 18mm; }
        .church { font-size: 13pt; font-weight: bold; color: #4A0E6B; }
        .doc-title { font-size: 11pt; color: #7A5F0C; }
        .event { text-align: right; }
        .event-name { font-size: 13pt; font-weight: bold; color: #6B1F8A; }
        .event-date { font-size: 10.5pt; color: #4B5563; }
        .gold { border-top: 1.4pt solid #C9A227; }

        /* Lignes de saisie : libellé au-dessus, trait sur toute la largeur. */
        .field { margin-top: 7mm; }
        .field .lab { font-size: 10pt; color: #4A0E6B; font-weight: bold; padding-bottom: 1mm; }
        .field .rule { border-bottom: 0.7pt solid #4B5563; height: 13mm; }

        /* Question à cocher. */
        .question { margin-top: 7mm; font-size: 10pt; color: #4A0E6B; font-weight: bold; }
        .choice { margin-top: 3mm; }
        .choice td { height: 10.5mm; vertical-align: bottom; padding-bottom: 0.5mm; }
        .check { border: 1pt solid #111827; height: 5mm !important; vertical-align: middle; }

        .choice-label { padding-left: 3mm; vertical-align: middle; }
        .inline-lab { font-size: 9.5pt; color: #4B5563; padding-left: 4mm; vertical-align: bottom; }
        .rule { border-bottom: 0.7pt solid #4B5563; }

        /* Consentement : case obligatoire et mention d'information. */
        /* Consentement : encadré clair, case à cocher détachée du texte. */
        .consent { margin-top: 9mm; background-color: #F7F1FB; border: 0.8pt solid #D9C8E4; }
        .consent td { vertical-align: middle; padding: 3.5mm 4mm; }
        .consent .text { font-weight: bold; padding-left: 4mm; }

        /* Pied de page : QR discret du lien public. */
        .foot { margin-top: 6mm; }
        .foot td { vertical-align: middle; border-top: 0.6pt solid #E6DEEC; padding-top: 3mm;
                   font-size: 9pt; color: #6B7280; }
        .foot .qr img { width: 20mm; }
        .foot .url { color: #6B1F8A; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td class="mark" style="width: 14%">
                @if ($logo !== '')
                    <img src="{{ $logo }}" alt="">
                @endif
            </td>
            <td style="width: 50%">
                <div class="church">{{ $churchName }}</div>
                <div class="doc-title">{{ $title }}</div>
            </td>
            <td class="event" style="width: 36%">
                <div class="event-name">{{ $eventName }}</div>
                @if ($eventDate !== '')
                    <div class="event-date">{{ $eventDate }}</div>
                @endif
            </td>
        </tr>
    </table>
    <div class="gold"></div>

    <table class="field">
        <tr><td class="lab">Nom et prénoms</td></tr>
        <tr><td class="rule"></td></tr>
    </table>

    <table class="field">
        <tr>
            <td class="lab" style="width: 50%">Téléphone</td>
            <td class="lab" style="width: 50%; padding-left: 6mm">Commune</td>
        </tr>
        <tr>
            <td class="rule" style="width: 50%"></td>
            <td style="width: 50%">
                <table>
                    <tr>
                        <td style="width: 10%"></td>
                        <td class="rule" style="width: 90%; height: 13mm"></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="field">
        <tr><td class="lab">Quartier</td></tr>
        <tr><td class="rule"></td></tr>
    </table>

    <div class="question">{{ $sourceQuestion }}</div>

    <table class="choice">
        <tr>
            <td class="check" style="width: 4%"></td>
            <td class="choice-label" style="width: 34%">{{ $invitedLabel }}</td>
            <td class="inline-lab" style="width: 14%">Son nom</td>
            <td class="rule" style="width: 48%"></td>
        </tr>
    </table>

    <table class="choice">
        <tr>
            <td class="check" style="width: 4%"></td>
            <td class="choice-label" style="width: 34%">{{ $otherLabel }}</td>
            <td class="inline-lab" style="width: 14%">Précisez</td>
            <td class="rule" style="width: 48%"></td>
        </tr>
    </table>

    <table class="choice">
        <tr>
            <td class="check" style="width: 4%"></td>
            <td class="choice-label" style="width: 96%">{{ $whatsapp }}</td>
        </tr>
    </table>

    <table class="field">
        <tr><td class="lab">Numéro WhatsApp, s'il est différent du téléphone</td></tr>
        <tr><td class="rule"></td></tr>
    </table>

    <table class="consent">
        <tr>
            <td class="check" style="width: 3%"></td>
            <td class="text" style="width: 97%">{{ $consent }}</td>
        </tr>
    </table>

    <table class="foot">
        <tr>
            <td class="qr" style="width: 16%">
                @if ($qr !== '')
                    <img src="{{ $qr }}" alt="">
                @endif
            </td>
            <td style="width: 84%; padding-left: 4mm">
                {{ $qrHint }}
                <div class="url">{{ $url }}</div>
            </td>
        </tr>
    </table>
</body>
</html>
