<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CongregationResource;
use App\Models\Congregation;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/admin/congregations (visitors.view) => `{ data: [ { id, name, active, position } ] }`,
 * dans l'ordre d'affichage voulu par l'Église.
 *
 * Sert le filtre « Congrégation » de la liste des visiteurs et la fiche visiteur. À ne pas
 * confondre avec /api/admin/families : la famille organise le service, la congrégation dit
 * à quelle assemblée la personne appartient.
 */
class CongregationController extends Controller
{
    public function __invoke(): AnonymousResourceCollection
    {
        return CongregationResource::collection(Congregation::query()->ordered()->get());
    }
}
