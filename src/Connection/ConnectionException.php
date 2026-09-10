<?php

namespace Kgkg\MigrationManager\Connection;

use Kgkg\MigrationManager\MigrationException;

/** A connection or SQL operation failed. Preserve the driver cause as previous. */
final class ConnectionException extends MigrationException
{
}
