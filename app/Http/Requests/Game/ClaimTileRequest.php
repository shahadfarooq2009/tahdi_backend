<?php

namespace App\Http\Requests\Game;

use App\Http\Requests\ApiFormRequest;

class ClaimTileRequest extends ApiFormRequest
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
        ];
    }
}
