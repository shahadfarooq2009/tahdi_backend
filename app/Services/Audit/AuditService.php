<?php

namespace App\Services\Audit;

use App\Models\AdminAuditLog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class AuditService
{
    private const SENSITIVE_PATTERN = '/(password|token|secret|key|authorization)/i';

    public function write(
        ?string $actorUserId,
        string $actionType,
        ?string $targetId,
        bool $success,
        array $metadata = [],
    ): void {
        $record = [
            'actorUserId' => $actorUserId,
            'actionType' => $actionType,
            'targetId' => $targetId,
            'timestamp' => now()->toIso8601String(),
            'success' => $success,
            'metadata' => $this->sanitize($metadata),
        ];

        $this->appendFile($record);
        $this->persistDatabase($record);
    }

    private function sanitize(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $sanitized = [];

        foreach ($value as $key => $nested) {
            if (is_string($key) && preg_match(self::SENSITIVE_PATTERN, $key)) {
                continue;
            }

            $sanitized[$key] = $this->sanitize($nested);
        }

        return $sanitized;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function appendFile(array $record): void
    {
        try {
            $path = storage_path('logs/audit.log');
            File::ensureDirectoryExists(dirname($path));
            File::append($path, json_encode($record).PHP_EOL);
        } catch (\Throwable $exception) {
            if (config('app.debug')) {
                Log::warning('[Audit] Failed to write audit file', ['error' => $exception->getMessage()]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function persistDatabase(array $record): void
    {
        try {
            AdminAuditLog::query()->create([
                'actor_user_id' => $record['actorUserId'],
                'action_type' => $record['actionType'],
                'target_id' => $record['targetId'],
                'success' => $record['success'],
                'metadata' => $record['metadata'],
                'created_at' => $record['timestamp'],
            ]);
        } catch (\Throwable $exception) {
            if (config('app.debug')) {
                Log::warning('[Audit] audit insert skipped', ['error' => $exception->getMessage()]);
            }
        }
    }
}
