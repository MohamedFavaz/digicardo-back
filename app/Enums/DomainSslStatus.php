<?php

namespace App\Enums;

enum DomainSslStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Failed = 'failed';
}
