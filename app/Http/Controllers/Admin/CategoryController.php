<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesActor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Services\Admin\CategoryService;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    use ResolvesActor;

    public function __construct(
        private readonly CategoryService $categories,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->success($this->categories->list([
            'is_deleted' => $request->query('is_deleted') === 'true',
            'search' => $request->query('search') ? trim((string) $request->query('search')) : null,
        ]));
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $data = $this->categories->create($request->validated(), $this->actor($request));
        $this->audit->write($request->attributes->get('auth_user')->id, 'CATEGORY_CREATED', $data['id'] ?? null, true);

        return $this->success($data, 201);
    }

    public function update(UpdateCategoryRequest $request, string $id): JsonResponse
    {
        $data = $this->categories->update($id, $request->validated(), $this->actor($request));
        $this->audit->write($request->attributes->get('auth_user')->id, 'CATEGORY_UPDATED', $id, true);

        return $this->success($data);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $data = $this->categories->softDelete($id, $this->actor($request));
        $this->audit->write($request->attributes->get('auth_user')->id, 'CATEGORY_DELETED', $id, true);

        return $this->success($data);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $data = $this->categories->restore($id, $this->actor($request));
        $this->audit->write($request->attributes->get('auth_user')->id, 'CATEGORY_RESTORED', $id, true);

        return $this->success($data);
    }
}
