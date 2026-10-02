<?php

namespace App\Http\Controllers\Admin;

use App\Exports\SocialPosterExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * GET /api/admin/reseaux-sociaux/affiche.pdf (visitors.view) — affichette « Suivez-nous ».
 *
 * Deux cartes par page A4 à poser sur les tables : le QR code mène à la page publique
 * /reseaux, qui liste les réseaux sociaux renseignés dans les paramètres.
 *
 * Aucun journal d'audit : le document ne contient aucune donnée personnelle.
 */
class SocialPosterController extends Controller
{
    public function __invoke(SocialPosterExport $export): Response
    {
        return $export->download();
    }
}
