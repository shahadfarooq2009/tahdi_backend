<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesActor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRoleRequest;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Services\Admin\UserService;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    use ResolvesActor;

    public function __construct(
        private readonly UserService $users,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->success($this->users->list([
            'is_deleted' => $request->query('is_deleted') === 'true',
            'search' => $request->query('search') ? trim((string) $request->query('search')) : null,
            'include_stats' => $request->query('include_stats') === 'true',
        ]));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->success($this->users->getById($id, $request->query('include_deleted') === 'true'));
    }

    public function updateRole(UpdateUserRoleRequest $request, string $userId): JsonResponse
    {
        $data = $this->users->changeRole($userId, $request->validated('role'), $this->actor($request));

        $this->audit->write($request->attributes->get('auth_user')->id, 'USER_ROLE_CHANGE', $userId, true, [
            'newRole' => $data['role'],
            'previousRole' => $data['previousRole'],
        ]);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function updateStatus(UpdateUserStatusRequest $request, string $id): JsonResponse
    {
        $data = $this->users->updateStatus($id, $request->validated('is_active'), $this->actor($request));
        $this->audit->write($request->attributes->get('auth_user')->id, 'USER_STATUS_UPDATED', $id, true, [
            'is_active' => $request->validated('is_active'),
        ]);

        return $this->success($data);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $data = $this->users->softDelete($id, $this->actor($request));
        $this->audit->write($request->attributes->get('auth_user')->id, 'USER_DELETED', $id, true);

        return $this->success($data);
    }

    public function permanentDestroy(Request $request, string $id): JsonResponse
    {
        $data = $this->users->permanentlyDelete($id, $this->actor($request));
        $this->audit->write($request->attributes->get('auth_user')->id, 'USER_PERMANENTLY_DELETED', $id, true);

        return $this->success($data);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $data = $this->users->restore($id, $this->actor($request));
        $this->audit->write($request->attributes->get('auth_user')->id, 'USER_RESTORED', $id, true);

        return $this->success($data);
    }
}
