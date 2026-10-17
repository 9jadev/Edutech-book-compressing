<?php

namespace App\Models;

use App\Enums\ChapterStatus;
use App\Enums\LessonsStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChapterBound extends Model
{
    protected $fillable = ['pdf_document_id', 'chapter_name', 'start_page', 'end_page', 'file_path', 'status', 'runtime_error', 'lessons_status', 'lessons_error', 'lessons_strategy'];

    protected $attributes = [
        'status' => 'pending',
        'lessons_status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'start_page' => 'integer',
            'end_page' => 'integer',
            'status' => ChapterStatus::class,
            'lessons_status' => LessonsStatus::class,
        ];
    }

    public function pdfDocument(): BelongsTo
    {
        return $this->belongsTo(PdfDocument::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->orderBy('position');
    }

    /**
     * Browser URL for the file, built from the current host so links work on any local domain.
     */
    public function publicUrl(): ?string
    {
        return $this->file_path ? asset('storage/'.$this->file_path) : null;
    }
}
