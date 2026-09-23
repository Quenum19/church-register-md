<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MemberIndexRequest;
use App\Http\Resources\MemberSummaryResource;
use App\Models\Member;
use App\Models\Visitor;
use App\Queries\VisitorListQuery;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/admin/members (visitors.view) : visiteurs convertis, du plus récemment converti au plus ancien.
 * Items = `VisitorSummary` + `converted_at`, `converted_by`.
 */
class MemberController extends Controller
{
    public function __invoke(MemberIndexRequest $request): JsonResponse
    {
        $query = Visitor::query()
            ->withVisitStats()
            ->with('member.convertedBy:id,name')
            ->whereHas('member');

        $search = $request->search();

        if ($search !== null) {
            VisitorListQuery::applySearch($query, $search);
        }

        $query->orderByDesc(
            Member::query()->select('converted_at')->whereColumn('members.visitor_id', 'visitors.id')->limit(1),
        )->orderByDesc('visitors.id');

        return Pagination::response(
            $query->paginate(Pagination::perPage($request)),
            static fn (Visitor $visitor): array => (new MemberSummaryResource($visitor))->resolve($request),
        );
    }
}
