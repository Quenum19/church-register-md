<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Users\StoreUserRequest;
use App\Http\Requests\Admin\Users\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\UserManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Administrateurs (contrat d'API §4, ability `users.manage` appliquée sur les routes).
 */
class UserController extends Controller
{
    public function __construct(private readonly UserManager $users) {}

    /**
     * GET /api/admin/users → { data: [User] } triés par nom.
     */
    public function index(Request $request): JsonResponse
    {
        $users = User::query()->orderBy('name')->orderBy('id')->get();

        return new JsonResponse([
            'data' => $users->map(fn (User $user): array => UserResource::make($user)->resolve($request))->values(),
        ]);
    }

    /**
     * POST /api/admin/users → 201 { data: User } + e-mail d'invitation (48 h).
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->users->invite($request->userData());

        return new JsonResponse(['data' => UserResource::make($user)->resolve($request)], 201);
    }

    /**
     * PATCH /api/admin/users/{user} → { data: User } ; 409 forbidden_self_change / last_super_admin.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $updated = $this->users->update($this->actor($request), $user, $request->validated());

        return new JsonResponse(['data' => UserResource::make($updated)->resolve($request)]);
    }

    /**
     * DELETE /api/admin/users/{user} → 204 ; 409 forbidden_self_change / last_super_admin.
     */
    public function destroy(Request $request, User $user): Response
    {
        $this->users->delete($this->actor($request), $user);

        return response()->noContent();
    }

    /**
     * POST /api/admin/users/{user}/invitation → 204 ; 409 invitation_not_pending.
     */
    public function resendInvitation(User $user): Response
    {
        $this->users->resendInvitation($user);

        return response()->noContent();
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
