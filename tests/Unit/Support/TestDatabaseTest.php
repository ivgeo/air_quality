<?php

namespace Tests\Unit\Support;

use App\Support\TestDatabase;
use Illuminate\Database\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TestDatabaseTest extends TestCase
{
    public function test_the_test_database_passes(): void
    {
        TestDatabase::ensure($this->connectedTo('air_quality_test'));

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function otherDatabases(): array
    {
        return [
            'development' => ['air_quality'],
            'information_schema' => ['information_schema'],
        ];
    }

    #[DataProvider('otherDatabases')]
    public function test_any_other_database_is_refused_with_both_names_and_the_hint(string $database): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Expected the "air_quality_test" database, but "%s" is connected. Check the config.', $database));

        TestDatabase::ensure($this->connectedTo($database), 'Check the config.');
    }

    public function test_the_actual_database_of_the_connection_is_checked_not_its_config(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabaseName')->willReturn('air_quality_test');
        $connection->method('scalar')->with('SELECT DATABASE()')->willReturn('air_quality');

        $this->expectException(RuntimeException::class);

        TestDatabase::ensure($connection);
    }

    private function connectedTo(string $database): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('scalar')->with('SELECT DATABASE()')->willReturn($database);

        return $connection;
    }
}
