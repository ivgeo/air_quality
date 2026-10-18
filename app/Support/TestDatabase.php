<?php

namespace App\Support;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * The dedicated test database, the only one the test suite and the benchmark may write to.
 */
final class TestDatabase
{
    public const string NAME = 'air_quality_test';

    /**
     * Checks the database the connection actually reaches (a DB_URL wins over the configured name).
     *
     * @param  string  $hint  appended to the error message
     *
     * @throws RuntimeException when it is not the test database
     */
    public static function ensure(Connection $connection, string $hint = ''): void
    {
        $database = (string) $connection->scalar('SELECT DATABASE()');

        if ($database !== self::NAME) {
            throw new RuntimeException(trim(sprintf('Expected the "%s" database, but "%s" is connected. %s', self::NAME, $database, $hint)));
        }
    }
}
