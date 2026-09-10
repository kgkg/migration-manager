<?php

namespace Kgkg\MigrationManager\Tests\Support;

trait ConsoleProcess
{
    protected function cli(array $arguments, ?string $entrypoint = null): array
    {
        $stdout = $this->temporaryDirectory . '/stdout.log';
        $stderr = $this->temporaryDirectory . '/stderr.log';
        $process = proc_open(array_merge([PHP_BINARY, $entrypoint ?? dirname(__DIR__, 2) . '/bin/migration-manager'], $arguments),
            [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']],
            $pipes, $this->temporaryDirectory);
        $this->assertIsResource($process);
        // Keep the input pipe open: a mistaken fgets() must time out instead of seeing EOF.
        $deadline = microtime(true) + 5;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        if ($status['running']) {
            proc_terminate($process);
        }
        fclose($pipes[0]);
        $closedCode = proc_close($process);
        $output = file_get_contents($stdout);
        $error = file_get_contents($stderr);
        unlink($stdout);
        unlink($stderr);
        $this->assertFalse($status['running'], 'CLI timed out while STDIN remained open.');
        return [$status['exitcode'] >= 0 ? $status['exitcode'] : $closedCode, $output, $error];
    }
}

