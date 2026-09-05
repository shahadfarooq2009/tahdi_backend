<?php

namespace App\Exceptions;

class ServiceUnavailableException extends ApiException
{
    public ?int $providerStatus = null;

    public function __construct(string $message = 'Service temporarily unavailable')
    {
        parent::__construct($message, 503, 'SERVICE_UNAVAILABLE');
    }
}
