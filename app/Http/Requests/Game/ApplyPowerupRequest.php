<?php

namespace App\Http\Requests\Game;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class ApplyPowerupRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'powerup_id' => ['required', 'string', Rule::in([
                'freePoints',
                'deductPoints',
                'teacher',
                'book',
                'assistant',
                'change',
                'shield',
                'time',
            ])],
            'team_index' => ['required', 'integer', 'min:0'],
        ];
    }
}
