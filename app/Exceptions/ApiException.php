<?php

namespace App\Exceptions;

use Exception;

class ApiException extends Exception
{
    public function __construct(
        string $message,
        protected int $statusCode = 500,
        protected string $errorCode = 'INTERNAL_ERROR',
        protected ?array $details = null,
    ) {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getDetails(): ?array
    {
        return $this->details;
    }

    public function toArray(): array
    {
        $payload = [
            'code' => $this->errorCode,
            'message' => $this->message,
        ];

        if ($this->details !== null) {
            $payload['details'] = $this->details;
        }

        return ['error' => $payload];
    }
}
