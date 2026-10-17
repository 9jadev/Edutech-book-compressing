<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lesson extends Model
{
    protected $fillable = [
        'chapter_bound_id', 'position', 'title', 'start_page', 'end_page',
        'printed_start_page', 'printed_end_page', 'file_path', 'content', 'warnings',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'start_page' => 'integer',
            'end_page' => 'integer',
            'printed_start_page' => 'integer',
            'printed_end_page' => 'integer',
            'warnings' => 'array',
        ];
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(ChapterBound::class, 'chapter_bound_id');
    }

    /**
     * Browser URL for the lesson PDF, built from the current host so links work on any local domain.
     */
    public function publicUrl(): ?string
    {
        return $this->file_path ? asset('storage/'.$this->file_path) : null;
    }
}
