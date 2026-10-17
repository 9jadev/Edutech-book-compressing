<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class Qpdf
{
    public function pageCount(string $pdfPath): int
    {
        return (int) trim($this->run(['--show-npages', $pdfPath]));
    }

    /**
     * Copy pages $startPage-$endPage (1-based, inclusive) of $sourcePath into a new PDF at $targetPath.
     */
    public function extractPages(string $sourcePath, int $startPage, int $endPage, string $targetPath): void
    {
        $this->run(['--empty', '--pages', $sourcePath, "{$startPage}-{$endPage}", '--', $targetPath]);
    }

    /**
     * @param  array<int, string>  $arguments
     */
    private function run(array $arguments): string
    {
        // Exit code 3 means qpdf succeeded with warnings (e.g. a slightly malformed source file).
        $result = Process::run([config('services.qpdf.binary'), '--warning-exit-0', ...$arguments]);

        if ($result->failed()) {
            throw new RuntimeException('qpdf failed: '.trim($result->errorOutput() ?: $result->output()));
        }

        return $result->output();
    }
}
