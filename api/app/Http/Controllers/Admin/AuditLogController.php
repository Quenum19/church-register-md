<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Users\ListAuditLogsRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Support\Pagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/admin/audit-logs (`audit.view`) : filtres `action`, `user_id`, paginé, plus récent d'abord.
 */
class AuditLogController extends Controller
{
    public function __invoke(ListAuditLogsRequest $request): JsonResponse
    {
        $action = $request->actionFilter();
        $userId = $request->userIdFilter();

        $logs = AuditLog::query()
            ->with('user:id,name')
            ->when($action !== null, fn (Builder $query) => $query->where('action', $action))
            ->when($userId !== null, fn (Builder $query) => $query->where('user_id', $userId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request));

        return Pagination::response($logs, fn (AuditLog $log): array => AuditLogResource::make($log)->resolve($request));
    }
}
