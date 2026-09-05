<?php

namespace App\Http\Requests\Game;

use App\Http\Requests\ApiFormRequest;

class ReviewSetUsageRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'textbook_id' => ['required', 'uuid'],
            'unit_key' => ['required', 'string'],
            'class_name' => ['required', 'string'],
            'game_session_id' => ['nullable', 'uuid'],
        ];
    }
}
