<?php

namespace App\Http\Requests\Game;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class SubmitQuestionOutcomeRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::in(['correct', 'no_answer'])],
            'team_index' => ['nullable', 'integer', 'min:0'],
            'row' => ['nullable', 'integer', 'min:0'],
            'col' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
