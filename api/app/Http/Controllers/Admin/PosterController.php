<?php

namespace App\Http\Controllers\Admin;

use App\Exports\PosterExport;
use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Response;

/**
 * Affiches A5 du QR code (visitors.view), prêtes à imprimer pour les porte-affiches :
 *   GET /api/admin/affiche.pdf                     formulaire habituel
 *   GET /api/admin/events/{event}/affiche.pdf      culte spécial
 *
 * Aucun journal d'audit : le document ne contient aucune donnée personnelle.
 */
class PosterController extends Controller
{
    public function visit(PosterExport $export): Response
    {
        return $export->download();
    }

    public function event(Event $event, PosterExport $export): Response
    {
        return $export->download($event);
    }
}
