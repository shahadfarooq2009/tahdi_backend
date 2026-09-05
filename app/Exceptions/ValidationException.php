<?php

namespace App\Exceptions;

class ValidationException extends ApiException
{
    public function __construct(string $message = 'البيانات المدخلة غير صالحة', ?array $details = null)
    {
        parent::__construct($message, 422, 'VALIDATION_ERROR', $details);
    }

    public function withDetails(array $details): self
    {
        return new self($this->getMessage(), $details);
    }
}
