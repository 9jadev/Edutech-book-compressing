<?php

namespace App\Enums;

/**
 * Where a chapter is in the lessons pipeline: Step 1 (prepare) then Step 2 (split into lessons).
 */
enum LessonsStatus: string
{
    case Pending = 'pending';
    case Preparing = 'preparing';
    case Splitting = 'splitting';
    case Done = 'done';
    case Failed = 'failed';
}
