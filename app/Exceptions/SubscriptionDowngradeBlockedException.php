<?php

namespace App\Exceptions;

use Exception;

class SubscriptionDowngradeBlockedException extends Exception
{
    protected array $details;

    public function __construct(string $message = 'Reduce usage before downgrading.', array $details = [])
    {
        parent::__construct($message);
        $this->details = $details;
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}
