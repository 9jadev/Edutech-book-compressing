<?php

namespace App\Enums;

enum ChapterStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Failed = 'failed';
}
