<?php

declare(strict_types=1);

namespace WilyCode\Database\DriverMysqli\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WilyCode\Database\Driver\Exception\NestedTransactionException;
use WilyCode\Database\Driver\Exception\QueryException;
use WilyCode\Database\DriverMysqli\MysqliDatabase;

final class MysqliDatabaseIntegrationTest extends TestCase
{
    private ?MysqliDatabase $db = null;
    private ?string $table = null;

    protected function setUp(): void
    {
        $host = getenv('MYSQL_TEST_HOST') ?: '';
        $user = getenv('MYSQL_TEST_USER') ?: '';
        $pass = getenv('MYSQL_TEST_PASS') ?: '';
        $name = getenv('MYSQL_TEST_DB')   ?: '';

        if ($host === '' || $user === '' || $name === '') {
            $this->markTestSkipped('MySQL integration tests disabled. Set MYSQL_TEST_HOST/USER/DB (and optionally PASS/PORT).');
        }

        $port = (int) (getenv('MYSQL_TEST_PORT') ?: 3306);

        $this->db = MysqliDatabase::connect(
            host: $host,
            username: $user,
            password: $pass,
            database: $name,
            port: $port,
            options: ['charset' => 'utf8mb4']
        );

        $this->table = 'users_' . bin2hex(random_bytes(4));

        $this->db->execute(
            'CREATE TABLE `' . $this->table . '` (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                email VARCHAR(190) NOT NULL,
                age INT NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_email (email)
            ) ENGINE=InnoDB'
        );
    }

    protected function tearDown(): void
    {
        if ($this->db === null || $this->table === null) {
            return;
        }

        try {
            $this->db->execute('DROP TABLE IF EXISTS `' . $this->table . '`');
        } catch (\Throwable) {
            // Cleanup must not hide an earlier test failure.
        }
    }

    public function test_transaction_commits(): void
    {
        $this->assertFalse($this->db->inTransaction());

        $this->db->transaction(function (MysqliDatabase $tx): void {
            $this->assertTrue($tx->inTransaction());

            $tx->execute(
                'INSERT INTO `' . $this->table . '` (email, age) VALUES (?, ?)',
                ['a@example.com', 20]
            );

            $tx->execute(
                'INSERT INTO `' . $this->table . '` (email, age) VALUES (?, ?)',
                ['b@example.com', 22]
            );
        });

        $this->assertFalse($this->db->inTransaction());
        $this->assertSame(2, $this->countUsers());
    }

    public function test_transaction_rolls_back_on_exception(): void
    {
        try {
            $this->db->transaction(function (MysqliDatabase $tx): void {
                $tx->execute(
                    'INSERT INTO `' . $this->table . '` (email, age) VALUES (?, ?)',
                    ['a@example.com', 20]
                );

                throw new RuntimeException('boom');
            });

            self::fail('Expected exception not thrown');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, $this->countUsers());
        $this->assertFalse($this->db->inTransaction());
    }

    public function test_transaction_throws_on_nested_transaction(): void
    {
        $this->expectException(NestedTransactionException::class);
        $this->expectExceptionMessage('Nested transactions are not supported');

        $this->db->transaction(function (MysqliDatabase $tx): void {
            $tx->transaction(static function (MysqliDatabase $_): void {
                // never reached
            });
        });
    }

    public function test_fetch_and_fetch_column(): void
    {
        $this->db->execute(
            'INSERT INTO `' . $this->table . '` (email, age) VALUES (?, ?)',
            ['x@example.com', 30]
        );

        $row = $this->db->fetch('SELECT email, age FROM `' . $this->table . '` WHERE email = ?', ['x@example.com']);
        $this->assertSame('x@example.com', $row['email'] ?? null);
        $this->assertSame(30, (int) ($row['age'] ?? 0));

        $count = $this->db->fetchColumn('SELECT COUNT(*) FROM `' . $this->table . '`');
        $this->assertSame(1, (int) $count);
    }

    public function test_fetch_all_returns_empty_list_for_missing_rows(): void
    {
        self::assertSame([], $this->db->fetchAll('SELECT id FROM `' . $this->table . '`'));
        self::assertSame([], $this->db->fetch('SELECT id FROM `' . $this->table . '`'));
        self::assertNull($this->db->fetchColumn('SELECT id FROM `' . $this->table . '`'));
    }

    public function test_last_insert_id_and_explicit_bind_types(): void
    {
        $this->db->execute(
            'INSERT INTO `' . $this->table . '` (email, age) VALUES (?, ?)',
            ['typed@example.com', 25],
            ['string', 'integer'],
        );

        self::assertSame('1', $this->db->getLastInsertId());
        self::assertSame(25, (int) $this->db->fetchColumn(
            'SELECT age FROM `' . $this->table . '` WHERE email = ?',
            ['typed@example.com'],
        ));
    }

    public function test_named_parameters_are_rejected_by_raw_mysqli_driver(): void
    {
        $this->expectException(QueryException::class);

        $this->db->fetch('SELECT :id AS id', ['id' => 10]);
    }

    public function test_query_prepare_failure_with_mysqli_reporting_off_is_not_silent(): void
    {
        $this->withErrorReportingOff(function (): void {
            $this->expectException(QueryException::class);
            $this->db->execute('THIS IS NOT VALID SQL');
        });
    }

    public function test_query_execute_failure_with_mysqli_reporting_off_is_not_silent(): void
    {
        $this->db->execute(
            'INSERT INTO `' . $this->table . '` (email, age) VALUES (?, ?)',
            ['unique@example.com', 10],
        );

        $this->withErrorReportingOff(function (): void {
            $this->expectException(QueryException::class);
            $this->db->execute(
                'INSERT INTO `' . $this->table . '` (email, age) VALUES (?, ?)',
                ['unique@example.com', 11],
            );
        });
    }

    public function test_boolean_type_maps_to_integer_binding(): void
    {
        $this->db->execute(
            'INSERT INTO `' . $this->table . '` (email, age) VALUES (?, ?)',
            ['bool@example.com', true],
            ['string', 'boolean'],
        );

        self::assertSame(1, (int) $this->db->fetchColumn(
            'SELECT age FROM `' . $this->table . '` WHERE email = ?',
            ['bool@example.com'],
        ));
    }

    public function test_blob_stream_can_be_inserted(): void
    {
        $this->db->execute('ALTER TABLE `' . $this->table . '` ADD payload LONGBLOB NULL');

        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);
        try {
            fwrite($stream, "binary\x00content");
            rewind($stream);
            $this->db->execute(
                'INSERT INTO `' . $this->table . '` (email, age, payload) VALUES (?, ?, ?)',
                ['blob@example.com', 10, $stream],
                ['s', 'i', 'b'],
            );
        } finally {
            fclose($stream);
        }

        self::assertSame("binary\x00content", $this->db->fetchColumn(
            'SELECT payload FROM `' . $this->table . '` WHERE email = ?',
            ['blob@example.com'],
        ));
    }

    public function test_rejects_unknown_bind_type(): void
    {
        $this->expectException(QueryException::class);

        $this->db->execute(
            'INSERT INTO `' . $this->table . '` (email, age) VALUES (?, ?)',
            ['typed@example.com', 25],
            ['unknown-type', 'i'],
        );
    }

    private function withErrorReportingOff(\Closure $assertions): void
    {
        $driver = new \mysqli_driver();
        $originalReportMode = $driver->report_mode;
        \mysqli_report(MYSQLI_REPORT_OFF);
        try {
            $assertions();
        } finally {
            \mysqli_report($originalReportMode);
        }
    }

    private function countUsers(): int
    {
        return (int) $this->db->fetchColumn('SELECT COUNT(*) FROM `' . $this->table . '`');
    }
}
