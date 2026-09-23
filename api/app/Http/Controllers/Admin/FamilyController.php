<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\FamilyResource;
use App\Models\Family;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/admin/families (visitors.view) => `{ data: [ { id, name, active, position } ] }`, ordre de rotation.
 */
class FamilyController extends Controller
{
    public function __invoke(): AnonymousResourceCollection
    {
        return FamilyResource::collection(Family::query()->ordered()->get());
    }
}
