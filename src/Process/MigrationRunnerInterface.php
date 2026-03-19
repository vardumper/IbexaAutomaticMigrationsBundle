<?php

declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\Process;

interface MigrationRunnerInterface
{
    /**
     * Run a console command as a subprocess.
     *
     * @param array<string> $command
     */
    public function run(array $command, string $workingDirectory): void;

    public function getExitCode(): ?int;

    public function getOutput(): string;

    public function getErrorOutput(): string;
}
