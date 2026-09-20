<?php

namespace App\Exceptions;

use Exception;

class InvalidWebhookSignatureException extends Exception
{
    public function __construct(string $message = 'Invalid webhook signature.')
    {
        parent::__construct($message);
    }
}
