<?php

namespace App\Models;

use App\Enums\PdfDocumentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PdfDocument extends Model
{
    protected $fillable = ['player_id', 'original_name', 'file_path', 'file_size', 'status', 'header_patterns'];

    protected $attributes = [
        'status' => 'started',
    ];

    protected function casts(): array
    {
        return [
            'status' => PdfDocumentStatus::class,
            'header_patterns' => 'array',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id');
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(ChapterBound::class)->orderBy('start_page');
    }

    /**
     * Browser URL for the file, built from the current host so links work on any local domain.
     */
    public function publicUrl(): ?string
    {
        return $this->file_path ? asset('storage/'.$this->file_path) : null;
    }

    /**
     * Regexes that remove this book's repeated header/footer lines, built from the plain text saved on upload.
     *
     * @return array<int, string>
     */
    public function headerRegexes(): array
    {
        return array_values(array_map(
            fn (string $text) => '/^.*'.preg_quote($text, '/').'.*$/mi',
            array_filter($this->header_patterns ?? [], fn ($text) => is_string($text) && trim($text) !== ''),
        ));
    }
}
