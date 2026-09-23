<?php

use App\Support\RateLimits;

return [

    /*
    |--------------------------------------------------------------------------
    | Identification publique — limite par adresse IP
    |--------------------------------------------------------------------------
    |
    | Identifications autorisées par heure et par IP sur POST /api/public/identify
    | (en plus des 5/heure par numéro et des 300 requêtes/min du groupe « public »).
    |
    | ATTENTION : tous les téléphones connectés au Wi-Fi de l'Église sortent sur UNE seule
    | adresse IP publique. La valeur doit absorber un dimanche chargé — bien plus de 100
    | identifications en une heure — tout en fermant l'oracle de présence (sans elle, une
    | IP peut tester environ 18 000 numéros par heure).
    |
    */

    'identify_per_ip_per_hour' => (int) env('RATE_LIMIT_IDENTIFY_PER_IP', RateLimits::IDENTIFY_PER_IP_PER_HOUR),

];
