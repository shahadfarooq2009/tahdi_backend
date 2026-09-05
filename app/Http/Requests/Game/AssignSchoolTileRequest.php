<?php

namespace App\Http\Requests\Game;

use App\Http\Requests\ApiFormRequest;

class AssignSchoolTileRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'row' => ['required', 'integer', 'min:0'],
            'col' => ['required', 'integer', 'min:0'],
            'team_index' => ['required', 'integer', 'min:0'],
        ];
    }
}
