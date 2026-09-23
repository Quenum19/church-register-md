<?php

namespace App\Http\Resources;

use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorNote;
use Illuminate\Http\Request;

/**
 * `VisitorDetail` du contrat d'API §4 = `VisitorSummary` + provenance, visites, notes et conversion.
 *
 * Construire avec VisitorDetailResource::fromVisitor() (charge RELATIONS) : sans ces relations,
 * la prévention du chargement paresseux lève une exception hors production.
 */
class VisitorDetailResource extends VisitorSummaryResource
{
    /** @var list<string> */
    public const RELATIONS = [
        'inviterFamily:id,name',
        'visits.family:id,name',
        'notes.author:id,name',
        'member.convertedBy:id,name',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $visitor = $this->resource;

        return [
            ...parent::toArray($request),
            'source' => $visitor->source->value,
            'source_other' => $visitor->source_other,
            'invited_by' => $visitor->invited_by,
            'inviter_family' => FamilyResource::ref($visitor->inviterFamily),
            'wants_whatsapp_group' => $visitor->wants_whatsapp_group,
            'consent_at' => self::timestamp($visitor->consent_at),
            'visits' => $visitor->visits->map(static fn (Visit $visit): array => [
                'id' => $visit->id,
                'visit_number' => $visit->visit_number,
                'visit_date' => $visit->visit_date->toDateString(),
                'family' => FamilyResource::ref($visit->family),
                // Objet JSON, y compris vide (« {} » pour la visite 1).
                'answers' => (object) $visit->answers,
            ])->values()->all(),
            // Relation déjà triée du plus récent au plus ancien (Visitor::notes()).
            'notes' => $visitor->notes
                ->map(static fn (VisitorNote $note): array => (new NoteResource($note))->toArray($request))
                ->values()
                ->all(),
            'member' => $visitor->member === null ? null : [
                'converted_at' => self::timestamp($visitor->member->converted_at),
                'converted_by' => self::userRef($visitor->member->convertedBy),
            ],
        ];
    }

    /**
     * Charge les relations de la fiche et renvoie la ressource.
     */
    public static function fromVisitor(Visitor $visitor): self
    {
        return new self($visitor->load(self::RELATIONS));
    }
}
