@extends('mail.reports.layout')

@section('preheader')
La configuration de l'envoi d'e-mails du registre fonctionne.
@endsection

@section('content')
    <p style="margin:0 0 16px;">Bonjour,</p>
    <p style="margin:0 0 16px;">
        Cet e-mail de test confirme que le registre des visiteurs peut envoyer des e-mails :
        les rapports mensuels seront bien distribués.
    </p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px; border-collapse:collapse; font-size:14px; line-height:20px;">
        <tr>
            <td style="padding:8px; border-bottom:1px solid #e5e7eb; color:#4b5563;" width="40%">Demandé par</td>
            <td style="padding:8px; border-bottom:1px solid #e5e7eb;">{{ $requestedBy }}</td>
        </tr>
        <tr>
            <td style="padding:8px; border-bottom:1px solid #e5e7eb; color:#4b5563;" width="40%">Envoyé le</td>
            <td style="padding:8px; border-bottom:1px solid #e5e7eb;">{{ $sentAt }}</td>
        </tr>
    </table>
    <p style="margin:0;">Aucune action n'est nécessaire.</p>
@endsection

@section('footer')
    Registre des visiteurs — {{ $churchName }}. E-mail de vérification de l'envoi.
@endsection
