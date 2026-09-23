@extends('mail.reports.layout')

@section('preheader')
Famille {{ $familyName }} : {{ $counts['total'] }} visite(s), {{ $counts['conversions'] }} conversion(s) en {{ $monthLabel }}.
@endsection

@section('content')
    <p style="margin:0 0 16px;">
        Famille de service : <strong>{{ $familyName }}</strong>
    </p>

    @if ($inProgress)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;">
            <tr>
                <td style="padding:10px 12px; background-color:#fbf3dc; border-left:4px solid #c9a227; font-size:14px; line-height:20px; color:#5c4708;">
                    Mois en cours : ce rapport est provisoire, il sera complet à la fin du mois.
                </td>
            </tr>
        </table>
    @endif

    @unless ($hasFamily)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;">
            <tr>
                <td style="padding:10px 12px; background-color:#fef2f2; border-left:4px solid #b91c1c; font-size:14px; line-height:20px; color:#7f1d1d;">
                    Aucune famille de service n'est définie pour ce mois dans la rotation.
                </td>
            </tr>
        </table>
    @endunless

    {{-- Compteurs --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px; border-collapse:collapse;">
        <tr>
            @foreach ($tiles as $tile)
                <td align="center" valign="top" width="20%" style="padding:12px 4px; border:1px solid #e5d4f2; background-color:#faf5ff;">
                    <p style="margin:0; font-size:24px; line-height:30px; font-weight:bold; color:#4a0e6b;">{{ $tile['value'] }}</p>
                    <p style="margin:2px 0 0; font-size:12px; line-height:16px; color:#374151;">{{ $tile['label'] }}</p>
                </td>
            @endforeach
        </tr>
    </table>

    {{-- Visiteurs accueillis --}}
    <h2 style="margin:0 0 8px; font-size:17px; line-height:24px; color:#4a0e6b;">Visiteurs accueillis ({{ count($visitors) }})</h2>
    @if (count($visitors) === 0)
        <p style="margin:0 0 24px; color:#374151;">Aucun visiteur accueilli par cette famille ce mois-ci.</p>
    @else
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px; border-collapse:collapse; font-size:14px; line-height:20px;">
            <tr>
                <th align="left" style="padding:8px; background-color:#f3f4f6; border-bottom:2px solid #d1d5db; color:#111827;">Nom</th>
                <th align="left" style="padding:8px; background-color:#f3f4f6; border-bottom:2px solid #d1d5db; color:#111827;">Téléphone</th>
                <th align="left" style="padding:8px; background-color:#f3f4f6; border-bottom:2px solid #d1d5db; color:#111827;">Visite</th>
                <th align="left" style="padding:8px; background-color:#f3f4f6; border-bottom:2px solid #d1d5db; color:#111827;">Date</th>
            </tr>
            @foreach ($visitors as $visitor)
                <tr>
                    <td valign="top" style="padding:8px; border-bottom:1px solid #e5e7eb;">{{ $visitor['full_name'] }}</td>
                    <td valign="top" style="padding:8px; border-bottom:1px solid #e5e7eb; white-space:nowrap;">{{ $visitor['phone'] }}</td>
                    <td valign="top" style="padding:8px; border-bottom:1px solid #e5e7eb; white-space:nowrap;">{{ $visitor['visit'] }}</td>
                    <td valign="top" style="padding:8px; border-bottom:1px solid #e5e7eb; white-space:nowrap;">{{ $visitor['date'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    {{-- Conversions --}}
    <h2 style="margin:0 0 8px; font-size:17px; line-height:24px; color:#4a0e6b;">Conversions ({{ count($conversions) }})</h2>
    <p style="margin:0 0 8px; font-size:13px; line-height:18px; color:#4b5563;">Membres convertis ce mois-ci dont la 1re visite a été accueillie par cette famille.</p>
    @if (count($conversions) === 0)
        <p style="margin:0 0 24px; color:#374151;">Aucune conversion ce mois-ci.</p>
    @else
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px; border-collapse:collapse; font-size:14px; line-height:20px;">
            <tr>
                <th align="left" style="padding:8px; background-color:#f3f4f6; border-bottom:2px solid #d1d5db; color:#111827;">Nom</th>
                <th align="left" style="padding:8px; background-color:#f3f4f6; border-bottom:2px solid #d1d5db; color:#111827;">Converti le</th>
            </tr>
            @foreach ($conversions as $conversion)
                <tr>
                    <td valign="top" style="padding:8px; border-bottom:1px solid #e5e7eb;">{{ $conversion['full_name'] }}</td>
                    <td valign="top" style="padding:8px; border-bottom:1px solid #e5e7eb; white-space:nowrap;">{{ $conversion['date'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    {{-- Lien vers le dashboard (bouton compatible Outlook) --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 8px;">
        <tr>
            <td align="center" bgcolor="#6b1f8a" style="background-color:#6b1f8a;">
                <a href="{{ $reportUrl }}" target="_blank" style="display:inline-block; padding:12px 20px; font-family:Arial, Helvetica, sans-serif; font-size:15px; font-weight:bold; line-height:20px; color:#ffffff; text-decoration:none;">Voir le rapport dans le registre</a>
            </td>
        </tr>
    </table>
    <p style="margin:0; font-size:12px; line-height:18px; color:#4b5563; word-break:break-all;">{{ $reportUrl }}</p>
@endsection

@section('footer')
    Registre des visiteurs — {{ $churchName }}. Cet e-mail est adressé aux destinataires des rapports mensuels.
    Il contient des données personnelles : merci de ne pas le transférer.
@endsection
