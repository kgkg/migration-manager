<?php

namespace Kgkg\MigrationManager;

/** Throw from down() when a migration cannot be safely reversed. */
final class IrreversibleMigrationException extends MigrationException
{
    public function __construct(
        string $message = 'This migration cannot be reversed.',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
