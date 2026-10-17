<?php

declare(strict_types=1);

namespace App\Services\Lessons\Prepare;

use RuntimeException;

/**
 * Finds and runs the command-line tools (poppler + tesseract).
 *
 * Why this exists: under Laravel Herd, php-fpm and queue workers often do not see
 * Homebrew's folder (/opt/homebrew/bin), so "pdftotext" is "not found" even though it
 * works in your terminal. Toolbox looks in config paths first, then common folders.
 */
final class Toolbox
{
    public const TOOLS = ['pdfinfo', 'pdftotext', 'pdftoppm', 'pdfimages', 'tesseract'];

    /** @var array<string,string> */
    private array $resolved = [];

    /**
     * @param array<string,?string> $binaries   tool => absolute path (null = search)
     * @param string[]              $searchDirs extra folders to search
     */
    public function __construct(
        private array $binaries = [],
        private array $searchDirs = ['/opt/homebrew/bin', '/usr/local/bin', '/usr/bin', '/bin'],
    ) {
    }

    public function path(string $tool): string
    {
        if (isset($this->resolved[$tool])) {
            return $this->resolved[$tool];
        }
        $configured = $this->binaries[$tool] ?? null;
        if ($configured) {
            if (!is_executable($configured)) {
                throw new RuntimeException("Configured path for $tool is not executable: $configured");
            }
            return $this->resolved[$tool] = $configured;
        }
        $dirs = array_merge($this->searchDirs, explode(PATH_SEPARATOR, (string) getenv('PATH')));
        foreach (array_unique(array_filter($dirs)) as $dir) {
            $candidate = rtrim($dir, '/') . '/' . $tool;
            if (is_file($candidate) && is_executable($candidate)) {
                return $this->resolved[$tool] = $candidate;
            }
        }
        throw new RuntimeException(
            "Missing tool: $tool. Install it (macOS: brew install poppler tesseract) "
            . "or set its full path in config/lessons.php under prepare.binaries."
        );
    }

    /** Throws if any required tool is missing. */
    public function assertInstalled(): void
    {
        foreach (self::TOOLS as $tool) {
            $this->path($tool);
        }
    }

    /**
     * Run a tool. $args excludes the tool name. Returns stdout.
     *
     * @param string[] $args
     */
    public function run(string $tool, array $args, int $timeout = 180, bool $allowFail = false, bool $withStderr = false): string
    {
        $cmd = array_merge([$this->path($tool)], $args);
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = ['PATH' => implode(PATH_SEPARATOR, array_unique(array_merge($this->searchDirs, explode(PATH_SEPARATOR, (string) getenv('PATH'))))),
                'OMP_THREAD_LIMIT' => '1',   // tesseract: one thread per process keeps the Mac responsive
                'LC_ALL' => 'C.UTF-8', 'LANG' => 'C.UTF-8'];
        if ($prefix = getenv('TESSDATA_PREFIX')) {
            $env['TESSDATA_PREFIX'] = $prefix;
        }
        $proc = proc_open($cmd, $spec, $pipes, null, $env);
        if (!is_resource($proc)) {
            throw new RuntimeException("Could not start $tool");
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $start = microtime(true);
        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $out .= (string) stream_get_contents($pipes[1]);
                $err .= (string) stream_get_contents($pipes[2]);
                $code = $status['exitcode'];
                break;
            }
            if (microtime(true) - $start > $timeout) {
                proc_terminate($proc, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                throw new RuntimeException("$tool timed out after {$timeout}s");
            }
            usleep(20000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        if ($code !== 0 && !$allowFail) {
            throw new RuntimeException("$tool failed (exit $code): " . trim(mb_substr($err, 0, 500)));
        }
        return $withStderr ? $out . "\n" . $err : $out;
    }

    /** @return array<string,string> tool => version line */
    public function versions(): array
    {
        $first = fn (string $s) => trim((string) strtok(trim($s), "\n"));
        return [
            'pdftotext' => $first($this->run('pdftotext', ['-v'], 20, true, true)),
            'tesseract' => $first($this->run('tesseract', ['--version'], 20, true, true)),
        ];
    }

    /** @return string[] installed tesseract languages */
    public function tesseractLanguages(): array
    {
        $out = $this->run('tesseract', ['--list-langs'], 20, true, true);
        $lines = array_map('trim', explode("\n", $out));
        return array_values(array_filter($lines, fn ($l) => $l !== '' && !str_contains($l, ' ') && !str_contains($l, ':')));
    }
}
