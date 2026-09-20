<?php

namespace App\Enums;

enum BlockType: string
{
    case Link = 'link';
    case Heading = 'heading';
    case Text = 'text';
    case Divider = 'divider';
    case Social = 'social';
    case Image = 'image';
    case Video = 'video';
}
