<?php

namespace App\Exceptions;

use Exception;

class ConflictException extends Exception
{
    protected ?int $currentVersion;

    public function __construct(string $message = 'The resource was updated by another request. Please reload.', ?int $currentVersion = null, int $code = 409)
    {
        parent::__construct($message, $code);
        $this->currentVersion = $currentVersion;
    }

    public function getCurrentVersion(): ?int
    {
        return $this->currentVersion;
    }
}
