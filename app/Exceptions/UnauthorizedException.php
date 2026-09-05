<?php

namespace App\Exceptions;

class UnauthorizedException extends ApiException
{
    public function __construct(string $message = 'Authentication required')
    {
        parent::__construct($message, 401, 'UNAUTHORIZED');
    }
}
