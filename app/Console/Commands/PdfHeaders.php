<?php

namespace App\Console\Commands;

use App\Models\PdfDocument;
use Illuminate\Console\Command;

class PdfHeaders extends Command
{
    protected $signature = 'pdfs:headers
                            {document : ID of the PDF.}
                            {text?* : Repeated header/footer text to remove from every page, e.g. "Created by Bernard Brochez". Replaces the saved list.}
                            {--clear : Remove all saved header/footer text.}';

    protected $description = 'Show or set the repeated header/footer text removed from a PDF\'s lessons';

    public function handle(): int
    {
        $document = PdfDocument::find($this->argument('document'));

        if (! $document) {
            $this->error("PDF #{$this->argument('document')} was not found.");

            return self::FAILURE;
        }

        if ($this->option('clear')) {
            $document->update(['header_patterns' => null]);
        } elseif ($texts = array_values(array_filter(array_map('trim', $this->argument('text'))))) {
            $document->update(['header_patterns' => $texts]);
        }

        $saved = $document->fresh()->header_patterns ?? [];
        $this->line("PDF #{$document->id} ({$document->original_name}) removes ".(count($saved) ? 'lines containing:' : 'no header/footer text.'));
        foreach ($saved as $text) {
            $this->line("  - {$text}");
        }

        if ($this->option('clear') || $this->argument('text')) {
            $this->info("Run \"php artisan pdfs:split-lessons --document={$document->id} --prepare\" to apply it to existing lessons.");
        }

        return self::SUCCESS;
    }
}
