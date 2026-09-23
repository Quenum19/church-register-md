<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tâches planifiées (cron hPanel : php artisan schedule:run chaque minute)
|--------------------------------------------------------------------------
|
| Contrat d'API §5 : reports:dispatch (08:00), visitors:purge (03:00), rotations:extend (le 1er),
| queue:work (chaque minute), plus la purge des liens de réinitialisation et d'invitation expirés.
|
*/

// Garantit 24 mois de rotation à l'avance.
Schedule::command('rotations:extend')->monthlyOn(1, '01:00')->withoutOverlapping();

// Rétention : visiteurs non membres sans visite depuis 24 mois, et journal d'audit de plus de 24 mois.
Schedule::command('visitors:purge')->dailyAt('03:00')->withoutOverlapping();

// Purge des liens expirés, chaque broker avec sa table et sa durée : réinitialisation
// (password_reset_tokens, 60 min) et invitation (invitation_tokens, 48 h).
Schedule::command('auth:clear-resets')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('auth:clear-resets invitations')->dailyAt('02:00')->withoutOverlapping();

// Rapport mensuel du mois précédent, CHAQUE JOUR à 08:00 : la commande est idempotente
// (report_dispatches), donc un envoi manqué le 1er (serveur arrêté, erreur Brevo) part au passage
// suivant (rattrapage automatique) sans jamais être envoyé deux fois. Verrou limité à 30 min :
// avec la durée par défaut (24 h), un verrou orphelin bloquerait aussi l'exécution du lendemain.
// Pas de onOneServer() : un seul serveur (Hostinger), et le verrou d'envoi du rapport suffit.
Schedule::command('reports:dispatch')
    ->dailyAt('08:00')
    ->timezone('Africa/Abidjan')
    ->withoutOverlapping(30);

// Hébergement mutualisé : pas de worker permanent, la file est vidée chaque minute.
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();
