<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\InvalidatesCaches;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateVisitorRequest;
use App\Http\Requests\Admin\VisitorIndexRequest;
use App\Http\Resources\VisitorDetailResource;
use App\Http\Resources\VisitorSummaryResource;
use App\Models\Visitor;
use App\Services\AuditLogger;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Visiteurs (contrat d'API §4) : liste filtrée paginée, fiche, modification, suppression.
 */
class VisitorController extends Controller
{
    use InvalidatesCaches;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * GET /api/admin/visitors (visitors.view).
     */
    public function index(VisitorIndexRequest $request): JsonResponse
    {
        $page = $request->listQuery()->builder()->paginate(Pagination::perPage($request));

        return Pagination::response(
            $page,
            static fn (Visitor $visitor): array => (new VisitorSummaryResource($visitor))->resolve($request),
        );
    }

    /**
     * GET /api/admin/visitors/{id} (visitors.view) => `{ data: VisitorDetail }`.
     */
    public function show(Visitor $visitor): VisitorDetailResource
    {
        return VisitorDetailResource::fromVisitor($visitor);
    }

    /**
     * PATCH /api/admin/visitors/{id} (visitors.update) : champs de la liste blanche uniquement.
     * Journal `visitor.updated` avec les NOMS des champs modifiés (jamais leurs valeurs).
     */
    public function update(UpdateVisitorRequest $request, Visitor $visitor): VisitorDetailResource
    {
        $visitor->fill($request->changes());
        $fields = array_values(array_intersect(UpdateVisitorRequest::FIELDS, array_keys($visitor->getDirty())));

        if ($fields !== []) {
            DB::transaction(function () use ($visitor, $fields): void {
                $visitor->save();
                $this->audit->log('visitor.updated', $visitor, ['fields' => $fields]);
            });
        }

        return VisitorDetailResource::fromVisitor($visitor);
    }

    /**
     * DELETE /api/admin/visitors/{id} (visitors.delete) : visites, notes et conversion
     * supprimées en cascade (clés étrangères ON DELETE CASCADE).
     */
    public function destroy(Visitor $visitor): Response
    {
        DB::transaction(function () use ($visitor): void {
            $this->audit->log('visitor.deleted', $visitor);
            $visitor->delete();
        });

        $this->forgetStats();

        return response()->noContent();
    }
}
