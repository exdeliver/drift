<?php

namespace Exdeliver\Drift\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class AnalyzeCodeDriftsCommand extends Command
{
    protected $signature = 'code:drifts';

    protected $description = 'Analyze code drifts using multiple tools';

    public function handle(): void
    {
        $this->info('Analyzing code drifts...');

        $this->runPhpInsights();
        $this->runPhpStan();
        $this->runPsalm();

        $this->info('Analysis complete.');
    }

    /**
     * @throws \JsonException
     */
    private function runPhpInsights(): void
    {
        $this->info('Running PHP Insights...');
        $process = new Process(['php', 'artisan', 'insights', '--format=json']);
        $process->run();

        if ($process->isSuccessful()) {
            $output = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $this->displayDrifts($output['files'] ?? [], 'PHP Insights');
        } else {
            $this->error('PHP Insights failed to run.' . $process->getErrorOutput());
        }
    }

    private function runPhpStan(): void
    {
        $this->info('Running PHPStan...');
        $process = new Process(['vendor/bin/phpstan', 'analyse', '--error-format=json']);
        $process->run();

        if ($process->getStatus() === 'terminated') {
            try {
                $output = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->error('PHPStan failed to run.' . $process->getErrorOutput());
            }
            $this->displayDrifts($output['files'] ?? [], 'PHPStan');
        } else {
            $this->error('PHPStan failed to run.' . $process->getErrorOutput());
        }
    }

    /**
     * @throws \JsonException
     */
    private function runPsalm(): void
    {
        $this->info('Running Psalm...');
        $process = new Process(['vendor/bin/psalm', '--output-format=json']);
        $process->run();

        if ($process->getStatus() === 'terminated') {
            try {
                $output = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->error('Psalm failed to run.' . $process->getErrorOutput());
            }
            $this->displayDrifts($output['issues'] ?? [], 'Psalm');
        } else {
            $this->error('Psalm failed to run.' . $process->getErrorOutput());
        }
    }

    private function displayDrifts(array $issues, string $tool): void
    {
        $drifts = $this->filterDrifts($issues, $tool);

        if (empty($drifts)) {
            $this->info("No drifts detected by $tool.");

            return;
        }

        $this->info("Drifts detected by $tool:");
        foreach ($drifts as $drift) {
            $this->line("- " . $drift);
        }
    }

    private function filterDrifts(array $issues, string $tool): array
    {
        $drifts = [];

        switch ($tool) {
            case 'PHP Insights':
                foreach ($issues as $file => $fileIssues) {
                    foreach ($fileIssues as $issue) {
                        if (strpos($issue['title'], 'New method') !== false || strpos($issue['title'], 'Method changed') !== false) {
                            $drifts[] = "{$file}: {$issue['title']}";
                        }
                    }
                }
                break;

            case 'PHPStan':
                foreach ($issues as $file => $fileIssues) {
                    foreach ($fileIssues['messages'] as $issue) {
                        if (strpos($issue['message'], 'New method') !== false || strpos($issue['message'], 'Method changed') !== false) {
                            $drifts[] = "{$file}:{$issue['line']}\n - {$issue['message']}";
                        }
                    }
                }
                break;

            case 'Psalm':
                foreach ($issues as $issue) {
                    if ($issue['type'] === 'NoNewMethodsIssue' || $issue['type'] === 'MethodChangeIssue') {
                        $drifts[] = "{$issue['file_name']}:{$issue['line_from']} - {$issue['message']}";
                    }
                }
                break;
        }

        return $drifts;
    }
}