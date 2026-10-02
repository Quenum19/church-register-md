{{--
    Affiche du QR code d'inscription (dompdf, A5 portrait, une seule page) — App\Exports\PosterExport.
    Format des porte-affiches posés à l'accueil et sur les tables.

    SÉCURITÉ : toutes les données sont affichées avec la syntaxe échappée de Blade. Ne jamais
    utiliser la syntaxe non échappée ici (nom de l'Église et nom de l'événement sont saisis par
    un administrateur). Le QR code arrive en data URI : dompdf tourne sans ressource distante.

    MISE EN PAGE : largeurs en pourcentage et `table-layout: fixed`. Mélanger millimètres et
    pourcentages dépasse 100 % dans dompdf et comprime la ligne.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $headline }} — {{ $eventName ?? $churchName }}</title>
    <style>
        @page { margin: 0; }

        body { font-family: "DejaVu Sans", sans-serif; color: #374151; margin: 0; }

        table { width: 100%; border-collapse: separate; border-spacing: 0; table-layout: fixed; }
        td { vertical-align: middle; text-align: center; }

        .band { height: 6mm; background-color: #C9A227; }

        /* Bandeau violet : identité de l'Église et, le cas échéant, le culte spécial. */
        .head { background-color: #4A0E6B; }
        .head td { padding: 9mm 12mm; }
        .church { font-size: 15pt; font-weight: bold; color: #FFFFFF; }
        .rule { width: 26mm; border-top: 1.4pt solid #C9A227; margin: 3.5mm auto; }
        .event { font-size: 17pt; font-weight: bold; color: #E8C547; }
        .subtitle { font-size: 11pt; color: #F3E8FF; font-style: italic; padding-top: 1.5mm; }

        .body { height: 139mm; background-color: #FFFFFF; }
        .body td { padding: 9mm 10mm 0 10mm; }
        .headline { font-size: 16pt; font-weight: bold; color: #6B1F8A; }
        .instruction { font-size: 10.5pt; color: #4B5563; padding-top: 2mm; }

        /* QR imprimé à 78 mm : lisible à un mètre, au-dessus d'une table. */
        .qr { padding-top: 5mm; }
        .qr img { width: 78mm; }

        .verse { font-size: 10.5pt; color: #4B5563; font-style: italic; padding-top: 5mm; }
        .verse-ref { font-size: 10pt; font-weight: bold; color: #7A5F0C; padding-top: 1.5mm; }
        .url { font-size: 9pt; color: #6B1F8A; padding-top: 3mm; }
    </style>
</head>
<body>
    <div class="band"></div>

    <table class="head">
        <tr>
            <td>
                <div class="church">{{ $churchName }}</div>
                <div class="rule"></div>
                @if ($eventName !== null)
                    <div class="event">{{ $eventName }}</div>
                @endif
                <div class="subtitle">{{ $subtitle }}</div>
            </td>
        </tr>
    </table>

    <table class="body">
        <tr>
            <td>
                <div class="headline">{{ $headline }}</div>
                <div class="instruction">{{ $instruction }}</div>

                @if ($qr !== '')
                    <div class="qr"><img src="{{ $qr }}" alt=""></div>
                @endif

                @if ($verseText !== '')
                    <div class="verse">« {{ $verseText }} »</div>
                    <div class="verse-ref">— {{ $verseRef }}</div>
                @endif

                <div class="url">{{ $url }}</div>
            </td>
        </tr>
    </table>

    <div class="band"></div>
</body>
</html>
