<?php

namespace App\Http\Controllers\Admin;

use App\Exports\EventFormExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EventFormRequest;
use App\Models\Event;
use Illuminate\Http\Response;

/**
 * GET /api/admin/events/{event}/formulaire.pdf (visitors.view) — fiche d'inscription papier.
 *
 * Deuxième voie d'enregistrement à côté du QR code, pour les personnes sans téléphone, la file
 * d'attente ou une batterie vide. Le téléchargement se fait avec le cookie de session (lien
 * direct du dashboard), comme les exports de visiteurs.
 *
 * Aucun journal d'audit : la fiche est vierge, elle ne contient aucune donnée personnelle
 * (contrairement aux exports de visiteurs, qui sont journalisés).
 */
class EventFormController extends Controller
{
    public function __invoke(EventFormRequest $request, Event $event, EventFormExport $export): Response
    {
        return $export->download($event, $request->perPage(), $export->filename($event));
    }
}
