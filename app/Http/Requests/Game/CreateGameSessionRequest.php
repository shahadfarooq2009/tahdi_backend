<?php

namespace App\Http\Requests\Game;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class CreateGameSessionRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(['family', 'school'])],
            'subject_ids' => ['required', 'array', 'min:1'],
            'subject_ids.*' => ['uuid'],
            'teams' => ['required', 'array', 'min:1'],
            'teams.*.name' => ['required', 'string', 'min:1'],
            'teams.*.avatar_url' => ['nullable', 'string'],
            'teams.*.avatar' => ['nullable', 'string'],
            'teams.*.color' => ['nullable', 'string'],
            'class_name' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sessionPayload(): array
    {
        $validated = $this->validated();

        return [
            'mode' => $validated['mode'],
            'class_name' => isset($validated['class_name']) ? trim($validated['class_name']) : null,
            'subject_ids' => $validated['subject_ids'],
            'teams' => collect($validated['teams'])->map(fn (array $team) => [
                'name' => trim($team['name']),
                'avatar_url' => $team['avatar_url'] ?? $team['avatar'] ?? null,
                'color' => $team['color'] ?? '#6B46C1',
            ])->all(),
            'metadata' => $validated['metadata'] ?? [],
        ];
    }
}
