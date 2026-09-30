{{--
    Fiche d'inscription papier d'un événement (dompdf, A4 portrait) — App\Exports\EventFormExport.

    SÉCURITÉ : toutes les données sont affichées avec la syntaxe échappée de Blade. Ne jamais
    utiliser la syntaxe non échappée ici (le nom d'un événement est saisi par un administrateur).
    Le logo et le QR code arrivent en data URI ($logo, $qr) : dompdf tourne sans ressource distante.

    MISE EN PAGE : $perPage vaut 1 ou 2. À deux fiches par page ($dense), chaque fiche occupe une
    hauteur fixe et un trait de coupe les sépare ; à une fiche par page, tout est plus aéré. Les
    lignes manuscrites font au moins 8 mm et les cases sont bordées de noir, pour rester lisibles
    sur une photocopie en noir et blanc.

    TABLEAUX : toutes les largeurs sont en `table-layout: fixed`, exprimées en POURCENTAGE et
    sommant à 100 par ligne. Deux pièges de dompdf à ne pas réintroduire :
      - en mise en page automatique, une cellule vide est réduite à presque rien (les traits de
        saisie et les cases à cocher disparaissent) ;
      - mélanger une largeur en millimètres et des pourcentages dépasse 100 % et comprime toute
        la ligne (les libellés se coupaient en deux).
--}}
@php($dense = $perPage === 2)
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} — {{ $eventName }}</title>
    <style>
        @page { margin: 8mm; }

        body { font-family: "DejaVu Sans", sans-serif; color: #1F2937;
               font-size: {{ $dense ? '9.5pt' : '11pt' }}; }

        table { width: 100%; border-collapse: separate; border-spacing: 0; table-layout: fixed; }
        td { vertical-align: middle; }

        /* Une fiche. Hauteur fixe en mode « deux par page » pour que la seconde tienne. */
        .sheet { height: {{ $dense ? '136mm' : 'auto' }}; }
        .cut { border-top: 0.8pt dashed #9CA3AF; }
        .cut-note { font-size: 6.5pt; color: #6B7280; text-align: center;
                    padding: 0.5mm 0 1.5mm 0; }

        /* En-tête : logo, identité de l'Église, titre, événement. */
        .head { background-color: #F3E8FF; border-bottom: 1.2pt solid #C9A227; }
        .head td { padding: {{ $dense ? '1.5mm 3mm' : '4mm 4mm' }}; vertical-align: middle; }
        .head .mark img { width: {{ $dense ? '10mm' : '20mm' }}; }
        .church { font-size: {{ $dense ? '11pt' : '14pt' }}; font-weight: bold; color: #4A0E6B; }
        .doc-title { font-size: {{ $dense ? '9.5pt' : '12pt' }}; color: #7A5F0C; font-weight: bold; }
        .event { text-align: right; }
        .event-name { font-size: {{ $dense ? '10pt' : '12.5pt' }}; font-weight: bold; color: #6B1F8A; }
        .event-date { font-size: {{ $dense ? '8.5pt' : '10.5pt' }}; color: #4B5563; }

        /* Lignes de saisie : libellé à gauche, trait manuscrit à droite (au moins 8 mm de haut). */
        .row { margin-top: {{ $dense ? '1.2mm' : '8mm' }}; }
        .row td { height: {{ $dense ? '8mm' : '12mm' }}; vertical-align: bottom;
                  padding-bottom: {{ $dense ? '0.2mm' : '0.5mm' }}; }
        .row.short td { height: {{ $dense ? '5.5mm' : '9mm' }}; }
        .lab { color: #4A0E6B; font-weight: bold; }
        .lab-light { color: #4B5563; padding-left: 2mm; }
        .rule { border-bottom: 0.7pt solid #374151; }

        /* Cases à un chiffre (téléphone, WhatsApp) : bordure franche, fond blanc. */
        .digit { border: 0.7pt solid #374151; }

        /* Case à cocher : carré bordé de noir. */
        .check { border: 0.9pt solid #111827; height: {{ $dense ? '4mm' : '5.5mm' }} !important; }

        .question { margin-top: {{ $dense ? '1.2mm' : '8mm' }}; color: #4A0E6B; font-weight: bold; }

        /* Consentement : case obligatoire et mention d'information. */
        .consent { margin-top: {{ $dense ? '1.2mm' : '8mm' }}; }
        .consent td { vertical-align: top; padding-bottom: 0; }
        .consent .bar { border-left: 2pt solid #C9A227; }
        .consent .text { font-weight: bold; padding-left: 2.5mm; }
        .notice { font-size: {{ $dense ? '7.5pt' : '9.5pt' }}; color: #4B5563; padding-left: 2.5mm; }

        /* Pied de fiche : QR code du lien public et zone réservée au service. */
        .foot { margin-top: {{ $dense ? '0.8mm' : '8mm' }}; }
        .foot td { vertical-align: middle; font-size: {{ $dense ? '7.5pt' : '9pt' }};
                   color: #4B5563; padding-top: {{ $dense ? '1.2mm' : '3mm' }};
                   border-top: 0.6pt solid #E6DEEC; }
        .foot .qr img { width: {{ $dense ? '25mm' : '32mm' }}; }
        .foot .hint { padding: 0 4mm; }
        .foot .url { color: #6B1F8A; }
        .service { border: 0.6pt solid #9CA3AF; padding: 1.5mm 2mm !important; }
        .service-title { font-size: {{ $dense ? '7pt' : '8.5pt' }}; font-weight: bold;
                         color: #4B5563; }
        .service td { border-top: 0; padding: 0; vertical-align: bottom;
                      height: {{ $dense ? '5mm' : '7mm' }}; }
        .service .line { border-bottom: 0.6pt solid #9CA3AF; }
    </style>
</head>
<body>
    @for ($sheet = 1; $sheet <= $perPage; $sheet++)
        @if ($sheet > 1)
            <div class="cut"></div>
            <div class="cut-note">— découper ici —</div>
        @endif

        <div class="sheet">
            <table class="head">
                <tr>
                    <td class="mark" style="width: {{ $dense ? 17 : 15 }}%">
                        @if ($logo !== '')
                            <img src="{{ $logo }}" alt="">
                        @endif
                    </td>
                    <td style="width: {{ $dense ? 41 : 46 }}%">
                        <div class="church">{{ $churchName }}</div>
                        <div class="doc-title">{{ $title }}</div>
                    </td>
                    <td class="event" style="width: {{ $dense ? 42 : 39 }}%">
                        <div class="event-name">{{ $eventName }}</div>
                        @if ($eventDate !== '')
                            <div class="event-date">{{ $eventDate }}</div>
                        @endif
                    </td>
                </tr>
            </table>

            <table class="row">
                <tr>
                    <td class="lab" style="width: {{ $dense ? 26 : 22 }}%">Nom et prénoms</td>
                    <td class="rule" style="width: {{ $dense ? 74 : 78 }}%"></td>
                </tr>
            </table>

            <table class="row">
                <tr>
                    <td class="lab" style="width: {{ $dense ? 26 : 22 }}%">Téléphone</td>
                    @for ($box = 1; $box <= $phoneBoxes; $box++)
                        <td class="digit" style="width: {{ $dense ? '3.6' : '4' }}%"></td>
                    @endfor
                    <td style="width: 38%"></td>
                </tr>
            </table>

            <table class="row">
                <tr>
                    <td class="lab" style="width: {{ $dense ? 18 : 16 }}%">Commune</td>
                    <td class="rule" style="width: {{ $dense ? 30 : 32 }}%"></td>
                    <td class="lab-light" style="width: 16%">Quartier</td>
                    <td class="rule" style="width: 36%"></td>
                </tr>
            </table>

            <div class="question">{{ $sourceQuestion }}</div>

            <table class="row">
                <tr>
                    <td class="check" style="width: 3%"></td>
                    <td style="width: 32%; padding-left: 2mm">{{ $invitedLabel }}</td>
                    <td class="lab-light" style="width: {{ $dense ? 18 : 20 }}%">Nom du membre</td>
                    <td class="rule" style="width: {{ $dense ? 47 : 45 }}%"></td>
                </tr>
            </table>

            <table class="row">
                <tr>
                    <td class="check" style="width: 3%"></td>
                    <td style="width: 32%; padding-left: 2mm">{{ $otherLabel }}</td>
                    <td class="lab-light" style="width: {{ $dense ? 18 : 20 }}%">Précisez</td>
                    <td class="rule" style="width: {{ $dense ? 47 : 45 }}%"></td>
                </tr>
            </table>

            <table class="row short">
                <tr>
                    <td class="check" style="width: 3%"></td>
                    <td style="width: 97%; padding-left: 2mm">{{ $whatsapp }}</td>
                </tr>
            </table>

            <table class="row">
                <tr>
                    <td class="lab-light" style="width: {{ $dense ? 30 : 34 }}%; padding-left: 0">Numéro WhatsApp si différent</td>
                    @for ($box = 1; $box <= $phoneBoxes; $box++)
                        <td class="digit" style="width: 3.6%"></td>
                    @endfor
                    <td style="width: {{ $dense ? 34 : 30 }}%"></td>
                </tr>
            </table>

            <table class="consent">
                <tr>
                    <td class="bar" style="width: 4%">
                        <table>
                            <tr>
                                <td class="check" style="width: 75%"></td>
                                <td style="width: 25%"></td>
                            </tr>
                        </table>
                    </td>
                    <td style="width: 96%">
                        <div class="text">{{ $consent }}</div>
                        <div class="notice">{{ $notice }}</div>
                    </td>
                </tr>
            </table>

            <table class="row">
                <tr>
                    <td class="lab" style="width: {{ $dense ? 12 : 10 }}%">Date</td>
                    <td class="rule" style="width: {{ $dense ? 36 : 38 }}%"></td>
                    <td class="lab-light" style="width: 16%">Signature</td>
                    <td class="rule" style="width: 36%"></td>
                </tr>
            </table>

            <table class="foot">
                <tr>
                    <td class="qr" style="width: {{ $dense ? 14 : 18 }}%">
                        @if ($qr !== '')
                            <img src="{{ $qr }}" alt="">
                        @endif
                    </td>
                    <td class="hint" style="width: {{ $dense ? 46 : 44 }}%">
                        {{ $qrHint }}
                        <div class="url">{{ $url }}</div>
                    </td>
                    <td style="width: {{ $dense ? 40 : 38 }}%">
                        <table class="service">
                            <tr>
                                <td colspan="4" style="height: {{ $dense ? '4mm' : '6mm' }}">
                                    <span class="service-title">Réservé au service</span>
                                </td>
                            </tr>
                            <tr>
                                <td style="width: 24%">Saisi le</td>
                                <td class="line" style="width: 30%"></td>
                                <td style="width: 12%; padding-left: 2mm">par</td>
                                <td class="line" style="width: 34%"></td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </div>
    @endfor
</body>
</html>
