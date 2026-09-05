<?php

namespace App\Services\Admin;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Models\Question;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;

class UserService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(array $filters): array
    {
        $query = UserProfile::query()
            ->where('is_deleted', (bool) ($filters['is_deleted'] ?? false));

        if ($filters['is_deleted'] ?? false) {
            $query->orderByDesc('deleted_at');
        } else {
            $query->orderByDesc('created_at');
        }

        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function ($builder) use ($search) {
                $builder->where('full_name', 'ilike', $search)
                    ->orWhere('email', 'ilike', $search);
            });
        }

        $users = $query->get();

        if (! ($filters['include_stats'] ?? false)) {
            return $users->map(fn (UserProfile $u) => $u->toArray())->all();
        }

        return $users->map(fn (UserProfile $u) => $this->enrichStats($u))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function getById(string $userId, bool $includeDeleted = false): array
    {
        $query = UserProfile::query()->where('id', $userId);

        if (! $includeDeleted) {
            $query->where('is_deleted', false);
        }

        $user = $query->first();

        if (! $user) {
            throw new NotFoundException('User not found');
        }

        return $user->toArray();
    }

    /**
     * @param  array{actorUserId: string, actorRole: string}  $actor
     * @return array<string, mixed>
     */
    public function updateStatus(string $userId, bool $isActive, array $actor): array
    {
        if ($actor['actorRole'] !== 'admin') {
            throw new ForbiddenException();
        }

        if ($userId === $actor['actorUserId']) {
            throw new ForbiddenException('Cannot change your own account status');
        }

        $this->getById($userId);

        UserProfile::query()->where('id', $userId)->update([
            'is_active' => $isActive,
            'updated_at' => now(),
        ]);

        return $this->getById($userId);
    }

    /**
     * @param  array{actorUserId: string, actorRole: string}  $actor
     * @return array{id: string, deleted: true}
     */
    public function softDelete(string $userId, array $actor): array
    {
        if ($actor['actorRole'] !== 'admin') {
            throw new ForbiddenException();
        }

        if ($userId === $actor['actorUserId']) {
            throw new ForbiddenException('Cannot delete your own account');
        }

        $this->getById($userId);
        DB::select('SELECT soft_delete_user_profile(?, ?)', [$userId, $actor['actorUserId']]);

        return ['id' => $userId, 'deleted' => true];
    }

    /**
     * @param  array{actorUserId: string, actorRole: string}  $actor
     * @return array<string, mixed>
     */
    public function restore(string $userId, array $actor): array
    {
        if ($actor['actorRole'] !== 'admin') {
            throw new ForbiddenException();
        }

        DB::select('SELECT restore_user_profile(?)', [$userId]);

        return $this->getById($userId, true);
    }

    /**
     * @param  array{actorUserId: string, actorRole: string}  $actor
     * @return array{id: string, permanently_deleted: true}
     */
    public function permanentlyDelete(string $userId, array $actor): array
    {
        if ($actor['actorRole'] !== 'admin') {
            throw new ForbiddenException();
        }

        if ($userId === $actor['actorUserId']) {
            throw new ForbiddenException('Cannot permanently delete your own account');
        }

        UserProfile::query()->where('id', $userId)->delete();

        return ['id' => $userId, 'permanently_deleted' => true];
    }

    /**
     * @return array{userId: string, role: string, previousRole: string}
     */
    public function changeRole(string $userId, string $newRole, array $actor): array
    {
        if ($actor['actorRole'] !== 'admin') {
            throw new ForbiddenException();
        }

        $target = UserProfile::query()->where('id', $userId)->first(['id', 'role']);

        if (! $target) {
            throw new NotFoundException('User not found');
        }

        UserProfile::query()->where('id', $userId)->update([
            'role' => $newRole,
            'updated_at' => now(),
        ]);

        return [
            'userId' => $target->id,
            'role' => $newRole,
            'previousRole' => $target->role,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function enrichStats(UserProfile $user): array
    {
        $row = $user->toArray();

        $row['question_count'] = Question::query()
            ->where('submitted_by', $user->id)
            ->where('is_deleted', false)
            ->count();

        $teamIds = DB::table('team_members')
            ->where('user_id', $user->id)
            ->pluck('team_id');

        $gameCount = 0;

        if ($teamIds->isNotEmpty()) {
            $sessionIds = DB::table('team_game_progress')
                ->whereIn('team_id', $teamIds)
                ->pluck('game_session_id')
                ->unique();

            if ($sessionIds->isNotEmpty()) {
                $gameCount = DB::table('game_sessions')
                    ->where('status', 'completed')
                    ->whereIn('id', $sessionIds)
                    ->count();
            }
        }

        $hostedGameCount = DB::table('game_sessions')
            ->where('created_by', $user->id)
            ->where('status', 'completed')
            ->count();

        $row['game_count'] = $gameCount + $hostedGameCount;
        $row['hosted_game_count'] = $hostedGameCount;

        return $row;
    }
}
