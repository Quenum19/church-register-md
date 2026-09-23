<?php

namespace App\Http\Controllers\Admin;

use App\Enums\VisitorStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Admin\Concerns\InvalidatesCaches;
use App\Http\Controllers\Controller;
use App\Http\Resources\VisitorDetailResource;
use App\Models\Member;
use App\Models\Visitor;
use App\Services\AuditLogger;
use App\Services\VisitorStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Conversion d'un visiteur en membre et annulation (contrat d'API §4).
 * Le statut est recalculé par VisitorStatusService dans la même transaction, visiteur verrouillé.
 */
class VisitorConversionController extends Controller
{
    use InvalidatesCaches;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly VisitorStatusService $status,
    ) {}

    /**
     * POST /api/admin/visitors/{id}/convert (visitors.convert).
     * 409 `already_member` si déjà membre ; 409 `not_eligible` si le statut n'est pas `membre_potentiel`.
     */
    public function store(Request $request, Visitor $visitor): VisitorDetailResource
    {
        DB::transaction(function () use ($request, $visitor): void {
            $locked = Visitor::query()->lockForUpdate()->findOrFail($visitor->id);
            $status = $this->status->refresh($locked);

            if ($status === VisitorStatus::Membre) {
                throw new ApiException('Ce visiteur est déjà membre.', 'already_member', 409);
            }

            if ($status !== VisitorStatus::MembrePotentiel) {
                throw new ApiException(
                    'La conversion en membre n\'est possible qu\'après la 3e visite (statut « Membre potentiel »).',
                    'not_eligible',
                    409,
                );
            }

            $member = Member::query()->create([
                'visitor_id' => $locked->id,
                'converted_by' => $request->user()?->getAuthIdentifier(),
                'converted_at' => now(),
            ]);

            $this->status->refresh($locked);
            $this->audit->log('visitor.converted', $locked, ['member_id' => $member->id]);
        });

        $this->forgetStats();

        return VisitorDetailResource::fromVisitor($visitor->refresh());
    }

    /**
     * DELETE /api/admin/visitors/{id}/convert (visitors.unconvert) : statut recalculé d'après les visites.
     * 409 `not_member` si le visiteur n'est pas membre.
     */
    public function destroy(Visitor $visitor): VisitorDetailResource
    {
        DB::transaction(function () use ($visitor): void {
            $locked = Visitor::query()->lockForUpdate()->findOrFail($visitor->id);
            $member = Member::query()->where('visitor_id', $locked->id)->lockForUpdate()->first();

            if ($member === null) {
                throw new ApiException('Ce visiteur n\'est pas membre.', 'not_member', 409);
            }

            $member->delete();
            $status = $this->status->refresh($locked);
            $this->audit->log('visitor.unconverted', $locked, ['status' => $status->value]);
        });

        $this->forgetStats();

        return VisitorDetailResource::fromVisitor($visitor->refresh());
    }
}
