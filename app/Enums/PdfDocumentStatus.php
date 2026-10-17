<?php

namespace App\Enums;

enum PdfDocumentStatus: string
{
    case Started = 'started';
    case Running = 'running';
    case Done = 'done';
}
