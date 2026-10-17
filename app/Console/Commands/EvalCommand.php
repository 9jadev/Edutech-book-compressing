<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Lessons\Eval\EvalSuite;
use Illuminate\Console\Command;
use Throwable;

/**
 * Run the test set against a model, to decide with numbers instead of guessing.
 *
 *   php artisan lessons:eval --step=plan                               (current model for each step)
 *   php artisan lessons:eval --step=write --model=deepseek-v4.1-flash  (try another model)
 *   php artisan lessons:eval --step=plan --step=write --case=bio-ch01
 *   php artisan lessons:eval --history                                 (compare all past runs)
 */
class EvalCommand extends Command
{
    protected $signature = 'lessons:eval
        {--step=* : structure, plan, write and/or visuals (default: plan and write)}
        {--case=* : Only these cases (default: all)}
        {--provider= : Provider to test (default: the step\'s configured provider)}
        {--model= : Model to test (default: the step\'s configured model)}
        {--disk= : Storage disk (default: lessons.prepare.disk)}
        {--history : Show past runs instead of running}';

    protected $description = 'Run the lessons test set and report pass rate, retries and cost for a model';

    public function handle(): int
    {
        $disk = $this->option('disk') ?: config('lessons.prepare.disk', 'local');
        $suite = new EvalSuite($disk, (string) config('lessons.eval.root', 'lessons-eval'));

        if ($this->option('history')) {
            $rows = array_map(fn ($h) => [
                $h['run'], $h['step'], $h['model'] ?? '-', "{$h['checks_passed']}/{$h['cases']}", "{$h['clean_first_pass']}/{$h['cases']}",
                "{$h['expect_passed']}/{$h['expect_total']}", "{$h['calls']} ({$h['failed_calls']})", $h['input_tokens'] + $h['output_tokens'],
                $h['cost_usd'] === null ? 'n/a' : '$' . $h['cost_usd'], $h['seconds'] . ' s',
            ], $suite->history());
            $this->table(['Run', 'Step', 'Model', 'Check passed', 'Right first time', 'Expectations', 'Calls (failed)', 'Tokens', 'Cost', 'Time'], $rows);
            return self::SUCCESS;
        }

        $cases = $this->option('case') ?: $suite->cases();
        if (!$cases) {
            $this->error('No test cases yet. Add one: php artisan lessons:eval-add pdfs/chapters/1/chapter-1 --name=bio-ch01');
            return self::FAILURE;
        }
        $steps = $this->option('step') ?: ['plan', 'write'];
        foreach ($steps as $s) {
            if (!in_array($s, EvalSuite::STEPS, true)) {
                $this->error("Unknown step \"$s\". Use: " . implode(', ', EvalSuite::STEPS));
                return self::FAILURE;
            }
        }

        try {
            $report = $suite->run($cases, $steps, $this->option('provider'), $this->option('model'), fn (string $m) => $this->line($m));
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Case', 'Step', 'Check', 'Errors 1st pass', 'Expectations', 'Calls (failed)', 'Tokens', 'Cost', 'Time'], array_map(fn ($r) => [
            $r['case'], $r['step'], $r['error'] ? 'CRASH' : ($r['check_passed'] ? 'pass' : 'fail'), $r['errors_first_pass'],
            "{$r['expect_passed']}/{$r['expect_total']}", "{$r['calls']} ({$r['failed_calls']})", $r['input_tokens'] + $r['output_tokens'],
            $r['cost_usd'] === null ? 'n/a' : '$' . $r['cost_usd'], $r['seconds'] . ' s',
        ], $report['results']));
        foreach ($report['results'] as $r) {
            if ($r['error']) {
                $this->error("{$r['case']} / {$r['step']}: {$r['error']}");
            }
            foreach ($r['failures'] as $f) {
                $this->warn("{$r['case']} / {$r['step']}: expected {$f['why']} — got {$f['actual']}");
            }
        }
        foreach ($report['summary'] as $step => $s) {
            $this->info(sprintf('%s with %s: check passed %d/%d, right first time %d/%d, expectations %d/%d, %d calls (%d failed), cost %s',
                $step, $s['model'] ?? 'default', $s['checks_passed'], $s['cases'], $s['clean_first_pass'], $s['cases'], $s['expect_passed'], $s['expect_total'],
                $s['calls'], $s['failed_calls'], $s['cost_usd'] === null ? 'n/a' : '$' . $s['cost_usd']));
        }
        $this->line('Report: ' . config('lessons.eval.root', 'lessons-eval') . "/runs/{$report['run']}/report.json");
        $all = array_sum(array_map(fn ($s) => $s['checks_passed'] === $s['cases'] && $s['expect_passed'] === $s['expect_total'] ? 1 : 0, $report['summary']));
        return $all === count($report['summary']) ? self::SUCCESS : self::FAILURE;
    }
}
