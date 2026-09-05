<?php

namespace App\Http\Requests\Game;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class UpdateGameSessionRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(['waiting', 'in_progress', 'completed'])],
            'active_team_index' => ['nullable', 'integer', 'min:0'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
