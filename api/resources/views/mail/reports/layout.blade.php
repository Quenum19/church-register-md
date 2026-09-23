{{--
    Gabarit HTML des e-mails de rapports : mise en page en tableaux et styles en ligne
    (Gmail, Outlook bureau / web, Apple Mail) ; ni grid, ni flex, ni feuille de style externe.
    Toute donnée est échappée avec {{ }} : ne jamais utiliser {!! !!} dans ces vues.
--}}
<!DOCTYPE html>
<html lang="fr" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $title }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f3e8ff; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%;">
    <div style="display:none; max-height:0; overflow:hidden; mso-hide:all;">@yield('preheader')</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3e8ff;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <!--[if mso]><table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:640px; background-color:#ffffff; border:1px solid #e5d4f2;">
                    <tr>
                        <td style="background-color:#4a0e6b; padding:20px 24px; border-bottom:4px solid #c9a227; font-family:Arial, Helvetica, sans-serif;">
                            <p style="margin:0; font-size:13px; line-height:18px; color:#f3e8ff;">{{ $churchName }}</p>
                            <h1 style="margin:4px 0 0; font-size:22px; line-height:28px; font-weight:bold; color:#ffffff;">{{ $title }}</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px; font-family:Arial, Helvetica, sans-serif; font-size:15px; line-height:22px; color:#1f2937;">
                            @yield('content')
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 24px; background-color:#f9fafb; border-top:1px solid #e5e7eb; font-family:Arial, Helvetica, sans-serif; font-size:12px; line-height:18px; color:#4b5563;">
                            @yield('footer')
                        </td>
                    </tr>
                </table>
                <!--[if mso]></td></tr></table><![endif]-->
            </td>
        </tr>
    </table>
</body>
</html>
