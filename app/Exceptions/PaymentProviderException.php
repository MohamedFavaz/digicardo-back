<?php

namespace App\Exceptions;

use Exception;

class PaymentProviderException extends Exception
{
    protected string $errorCode;
    protected int $statusCode;
    protected ?array $details;

    public function __construct(
        string $message = 'Payment provider error occurred.',
        string $errorCode = 'PAYMENT_PROVIDER_UNAVAILABLE',
        int $statusCode = 400,
        ?array $details = null
    ) {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->statusCode = $statusCode;
        $this->details = $details;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getDetails(): ?array
    {
        return $this->details;
    }
}
