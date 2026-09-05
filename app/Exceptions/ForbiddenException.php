<?php

namespace App\Exceptions;

class ForbiddenException extends ApiException
{
    public function __construct(string $message = 'Insufficient permissions')
    {
        parent::__construct($message, 403, 'FORBIDDEN');
    }
}
