<?php

declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\Process;

use Symfony\Component\Process\Process;

final class SymfonyProcessRunner implements MigrationRunnerInterface
{
    private ?Process $process = null;

    public function run(array $command, string $workingDirectory): void
    {
        $this->process = new Process($command, $workingDirectory);
        $this->process->run();
    }

    public function getExitCode(): ?int
    {
        return $this->process?->getExitCode();
    }

    public function getOutput(): string
    {
        return $this->process?->getOutput() ?? '';
    }

    public function getErrorOutput(): string
    {
        return $this->process?->getErrorOutput() ?? '';
    }
}
