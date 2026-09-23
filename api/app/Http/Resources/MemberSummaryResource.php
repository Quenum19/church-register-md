<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Élément de GET /api/admin/members : `VisitorSummary` + `converted_at`, `converted_by`.
 * Relations à charger : `member.convertedBy:id,name` (et les agrégats de visites).
 */
class MemberSummaryResource extends VisitorSummaryResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $member = $this->resource->member;

        return [
            ...parent::toArray($request),
            'converted_at' => self::timestamp($member?->converted_at),
            'converted_by' => self::userRef($member?->convertedBy),
        ];
    }
}
