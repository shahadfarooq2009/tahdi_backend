<?php

namespace App\Services\Admin;

use App\Exceptions\ConflictException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Models\Category;
use App\Support\AdminRequestTiming;
use App\Support\Roles;

class CategoryService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(array $filters): array
    {
        $queryStartedAt = microtime(true);

        $query = Category::query()
            ->where('is_deleted', (bool) ($filters['is_deleted'] ?? false));

        if ($filters['is_deleted'] ?? false) {
            $query->orderByDesc('deleted_at');
        } else {
            $query->orderBy('name');
        }

        if (! empty($filters['search'])) {
            $query->where('name', 'ilike', '%'.$filters['search'].'%');
        }

        $rows = $query->get()->map(fn (Category $c) => $c->toArray())->all();

        AdminRequestTiming::segment('category_service_ms', (microtime(true) - $queryStartedAt) * 1000);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function getById(string $categoryId): array
    {
        $category = Category::query()->where('id', $categoryId)->first();

        if (! $category) {
            throw new NotFoundException('Category not found');
        }

        return $category->toArray();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{actorUserId: string, actorRole: string}  $actor
     * @return array<string, mixed>
     */
    public function create(array $payload, array $actor): array
    {
        if (! Roles::roleHasPermission($actor['actorRole'], 'canAddCategories')) {
            throw new ForbiddenException();
        }

        $this->assertCategoryNameUnique($payload['name']);

        try {
            $category = Category::query()->create($payload);
        } catch (\Illuminate\Database\QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23505') {
                throw new ConflictException('Category with this name already exists');
            }
            throw $exception;
        }

        return $category->toArray();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{actorUserId: string, actorRole: string}  $actor
     * @return array<string, mixed>
     */
    public function update(string $categoryId, array $payload, array $actor): array
    {
        if (! Roles::roleHasPermission($actor['actorRole'], 'canEditCategories')) {
            throw new ForbiddenException();
        }

        $existing = $this->getById($categoryId);

        if (! empty($payload['name']) && $payload['name'] !== $existing['name']) {
            $this->assertCategoryNameUnique($payload['name'], $categoryId);
        }

        $payload['updated_at'] = now();

        try {
            Category::query()->where('id', $categoryId)->update($payload);
        } catch (\Illuminate\Database\QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23505') {
                throw new ConflictException('Category with this name already exists');
            }
            throw $exception;
        }

        return $this->getById($categoryId);
    }

    /**
     * @param  array{actorUserId: string, actorRole: string}  $actor
     * @return array{id: string, deleted: true}
     */
    public function softDelete(string $categoryId, array $actor): array
    {
        if (! Roles::roleHasPermission($actor['actorRole'], 'canDeleteCategories')) {
            throw new ForbiddenException();
        }

        $this->getById($categoryId);
        \DB::select('SELECT soft_delete_category(?, ?)', [$categoryId, $actor['actorUserId']]);

        return ['id' => $categoryId, 'deleted' => true];
    }

    /**
     * @param  array{actorUserId: string, actorRole: string}  $actor
     * @return array<string, mixed>
     */
    public function restore(string $categoryId, array $actor): array
    {
        if (! Roles::roleHasPermission($actor['actorRole'], 'canEditCategories')) {
            throw new ForbiddenException();
        }

        \DB::select('SELECT restore_category(?)', [$categoryId]);

        return $this->getById($categoryId);
    }

    private function assertCategoryNameUnique(string $name, ?string $excludeId = null): void
    {
        $query = Category::query()
            ->where('name', $name)
            ->where('is_deleted', false);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw new ConflictException('Category with this name already exists');
        }
    }
}
