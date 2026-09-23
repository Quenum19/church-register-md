{{--
    Version texte du rapport mensuel. Données échappées avec {{ }} comme la version HTML ;
    les entités sont décodées après rendu (App\Mail\Concerns\RendersPlainTextAlternative).
--}}
{{ $churchName }}
{{ $title }}

Famille de service : {{ $familyName }}
@if ($inProgress)
Mois en cours : ce rapport est provisoire, il sera complet à la fin du mois.
@endif
@unless ($hasFamily)
Aucune famille de service n'est définie pour ce mois dans la rotation.
@endunless

@foreach ($tiles as $tile)
{{ $tile['label'] }} : {{ $tile['value'] }}
@endforeach

VISITEURS ACCUEILLIS ({{ count($visitors) }})
@forelse ($visitors as $visitor)
- {{ $visitor['full_name'] }} | {{ $visitor['phone'] }} | {{ $visitor['visit'] }} | {{ $visitor['date'] }}
@empty
Aucun visiteur accueilli par cette famille ce mois-ci.
@endforelse

CONVERSIONS ({{ count($conversions) }})
@forelse ($conversions as $conversion)
- {{ $conversion['full_name'] }} | converti le {{ $conversion['date'] }}
@empty
Aucune conversion ce mois-ci.
@endforelse

Voir le rapport dans le registre : {{ $reportUrl }}

--
Registre des visiteurs — {{ $churchName }}. Cet e-mail est adressé aux destinataires des rapports mensuels.
Il contient des données personnelles : merci de ne pas le transférer.
