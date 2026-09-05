<?php

namespace App\Http\Requests\Me;

use Illuminate\Foundation\Http\FormRequest;

class StartPlaySetGameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'class_name' => ['required', 'string', 'max:120'],
            'teams' => ['required', 'array', 'min:2', 'max:6'],
            'teams.*.name' => ['required', 'string', 'max:80'],
            'teams.*.avatar_url' => ['nullable', 'string', 'max:500'],
            'teams.*.avatar' => ['nullable', 'string', 'max:500'],
            'teams.*.color' => ['nullable', 'string', 'max:20'],
            'selected_powers' => ['required', 'array', 'min:1', 'max:3'],
            'selected_powers.*' => ['required', 'string', 'max:40'],
        ];
    }

    /**
     * @return array{class_name: string, teams: array<int, array{name: string, avatar_url?: string|null, color?: string|null}>, selected_powers: array<int, string>}
     */
    public function gamePayload(): array
    {
        $validated = $this->validated();

        $teams = array_map(function (array $team): array {
            return [
                'name' => $team['name'],
                'avatar_url' => $team['avatar_url'] ?? $team['avatar'] ?? null,
                'color' => $team['color'] ?? '#6B46C1',
            ];
        }, $validated['teams']);

        return [
            'class_name' => $validated['class_name'],
            'teams' => $teams,
            'selected_powers' => array_values($validated['selected_powers']),
        ];
    }
}
