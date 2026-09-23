<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\InvalidatesCaches;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RotationIndexRequest;
use App\Http\Requests\Admin\UpdateRotationRequest;
use App\Http\Resources\FamilyResource;
use App\Models\Family;
use App\Models\FamilyRotation;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Rotation mensuelle des familles d'accueil (contrat d'API §4).
 */
class RotationController extends Controller
{
    use InvalidatesCaches;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * GET /api/admin/rotations?from=YYYY-MM&months=1..36 (visitors.view) : TOUS les mois de la
     * plage, `family` = null pour un mois sans rotation.
     */
    public function index(RotationIndexRequest $request): JsonResponse
    {
        [$year, $month] = $request->start();
        $first = $year * 12 + $month - 1;
        $last = $first + $request->months() - 1;

        $rotations = FamilyRotation::query()
            ->with('family:id,name')
            ->whereRaw('(year * 12 + month - 1) BETWEEN ? AND ?', [$first, $last])
            ->get()
            ->keyBy(static fn (FamilyRotation $rotation): int => $rotation->year * 12 + $rotation->month - 1);

        $data = [];

        for ($index = $first; $index <= $last; $index++) {
            $data[] = [
                'year' => intdiv($index, 12),
                'month' => $index % 12 + 1,
                'family' => FamilyResource::ref($rotations->get($index)?->family),
            ];
        }

        return new JsonResponse(['data' => $data]);
    }

    /**
     * PUT /api/admin/rotations/{year}/{month} (rotations.manage) `{ family_id }`
     * => `{ data: { year, month, family } }`. Journal `rotation.updated` si la famille change.
     */
    public function update(UpdateRotationRequest $request): JsonResponse
    {
        $year = $request->year();
        $month = $request->month();
        $family = Family::query()->findOrFail($request->familyId(), ['id', 'name']);

        DB::transaction(function () use ($year, $month, $family): void {
            $rotation = FamilyRotation::query()
                ->where('year', $year)
                ->where('month', $month)
                ->lockForUpdate()
                ->first();

            $previous = $rotation?->family_id;

            if ($rotation === null) {
                $rotation = FamilyRotation::query()->create(['year' => $year, 'month' => $month, 'family_id' => $family->id]);
            } elseif ($previous !== $family->id) {
                $rotation->update(['family_id' => $family->id]);
            } else {
                return;
            }

            $this->audit->log('rotation.updated', $rotation, [
                'year' => $year,
                'month' => $month,
                'family_id' => $family->id,
                'previous_family_id' => $previous,
            ]);
        });

        $this->forgetPublicConfig();
        $this->forgetStats();

        return new JsonResponse(['data' => [
            'year' => $year,
            'month' => $month,
            'family' => FamilyResource::ref($family),
        ]]);
    }
}
