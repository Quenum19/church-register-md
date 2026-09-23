<?php

/*
|--------------------------------------------------------------------------
| Proxies de confiance (lu par Illuminate\Http\Middleware\TrustProxies)
|--------------------------------------------------------------------------
|
| TRUSTED_PROXIES : liste d'IP ou de plages CIDR séparées par des virgules, ou « * ».
|
| - Vide (défaut) : aucun proxy n'est de confiance ; les en-têtes X-Forwarded-* sont ignorés
|   et l'IP du client est REMOTE_ADDR. C'est le bon réglage sur Hostinger mutualisé sans CDN :
|   le serveur web reçoit directement la connexion du navigateur, REMOTE_ADDR est l'IP réelle.
| - CDN / proxy Hostinger activé : REMOTE_ADDR devient l'IP du proxy ; il faut alors lister
|   ses plages d'IP ici, sinon toutes les requêtes partagent la même IP et les limites de débit
|   (300/min par IP, connexion par e-mail + IP) deviennent communes à tous les visiteurs.
| - « * » fait confiance à n'importe quel appelant : un client peut alors forger X-Forwarded-For
|   pour contourner les limites de débit. Déconseillé, sauf si le serveur n'est joignable
|   QUE via le proxy.
|
*/

$proxies = trim((string) env('TRUSTED_PROXIES', ''));

return [

    'proxies' => match (true) {
        $proxies === '' => null,
        $proxies === '*' || $proxies === '**' => '*',
        default => array_values(array_filter(array_map('trim', explode(',', $proxies)))),
    },

];
