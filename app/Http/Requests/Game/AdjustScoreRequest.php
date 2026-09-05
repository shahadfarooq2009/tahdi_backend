<?php

namespace App\Http\Requests\Game;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class AdjustScoreRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'team_index' => ['required', 'integer', 'min:0'],
            'delta' => ['required', 'integer', Rule::in([-100, 100])],
        ];
    }
}
