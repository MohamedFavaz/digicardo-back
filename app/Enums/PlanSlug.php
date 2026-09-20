<?php

namespace App\Enums;

enum PlanSlug: string
{
    case Free = 'free';
    case Pro = 'pro';
    case Business = 'business';
}
