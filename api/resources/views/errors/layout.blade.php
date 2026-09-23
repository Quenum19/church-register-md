<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') — {{ config('app.name') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: system-ui, sans-serif; background: #f8fafc; color: #1e293b; }
        main { padding: 2rem; text-align: center; max-width: 32rem; }
        h1 { font-size: 1.25rem; margin: 0 0 .5rem; }
        p { margin: 0 0 1.5rem; color: #475569; }
        a { color: #1d4ed8; }
    </style>
</head>
<body>
<main>
    <h1>@yield('title')</h1>
    <p>@yield('message')</p>
    <a href="/">Retour à l'accueil</a>
</main>
</body>
</html>
