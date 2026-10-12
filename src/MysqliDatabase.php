<?php

declare(strict_types=1);

namespace WilyCode\Database\DriverMysqli;

use mysqli;
use mysqli_result;
use mysqli_sql_exception;
use mysqli_stmt;
use Throwable;
use WilyCode\Database\Driver\DatabaseInterface;
use WilyCode\Database\Driver\Exception\ConnectionException;
use WilyCode\Database\Driver\Exception\DatabaseException;
use WilyCode\Database\Driver\Exception\NestedTransactionException;
use WilyCode\Database\Driver\Exception\QueryException;
use WilyCode\Database\Driver\Exception\TransactionException;

final class MysqliDatabase implements DatabaseInterface
{
    private bool $inTransaction = false;

    public function __construct(private readonly mysqli $mysqli) {}

    /**
     * @param array{charset?:string,connect_timeout?:int,flags?:int,report_mode?:int} $options
     * @throws ConnectionException
     */
    public static function connect(
        string $host,
        ?string $username = null,
        ?string $password = null,
        ?string $database = null,
        ?int $port = null,
        ?string $socket = null,
        array $options = [],
    ): self {
        mysqli_report((int) ($options['report_mode'] ?? (MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT)));

        try {
            $mysqli = mysqli_init();
            if (!$mysqli instanceof mysqli) {
                throw new ConnectionException('Failed to initialize MySQLi.');
            }

            $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, (int) ($options['connect_timeout'] ?? 3));
            if (defined('MYSQLI_OPT_INT_AND_FLOAT_NATIVE')) {
                $mysqli->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);
            }

            $connected = $mysqli->real_connect(
                $host,
                $username ?? '',
                $password ?? '',
                $database ?? '',
                $port ?? 3306,
                $socket,
                (int) ($options['flags'] ?? 0),
            );
            if (!$connected) {
                throw new ConnectionException('Failed to create MySQLi connection.');
            }

            $charset = (string) ($options['charset'] ?? 'utf8mb4');
            if ($charset !== '') {
                if (!$mysqli->set_charset($charset)) {
                    throw new ConnectionException('Failed to set MySQLi connection charset.');
                }
            }

            return new self($mysqli);
        } catch (mysqli_sql_exception $e) {
            throw new ConnectionException('Failed to create MySQLi connection.', 0, $e);
        }
    }

    /**
     * @param array<int|string, mixed> $params
     * @param array<int|string, mixed> $types
     */
    public function execute(string $query, array $params = [], array $types = []): int
    {
        try {
            $statement = $this->prepareAndExecute($query, $params, $types);
            try {
                $affectedRows = $statement->affected_rows;
            } finally {
                $statement->close();
            }

            if ($affectedRows < 0) {
                throw new DatabaseException('The driver did not return a valid affected-row count.');
            }

            return $affectedRows;
        } catch (Throwable $e) {
            throw QueryException::fromThrowable($e, $query, $params);
        }
    }

    /**
     * @param array<int|string, mixed> $params
     * @param array<int|string, mixed> $types
     */
    public function fetch(string $query, array $params = [], array $types = []): array
    {
        $rows = $this->fetchAll($query, $params, $types);
        return $rows[0] ?? [];
    }

    /**
     * @param array<int|string, mixed> $params
     * @param array<int|string, mixed> $types
     */
    public function fetchAll(string $query, array $params = [], array $types = []): array
    {
        try {
            $statement = $this->prepareAndExecute($query, $params, $types);
            try {
                return $statement->field_count === 0 ? [] : $this->fetchAllFromStatement($statement);
            } finally {
                $statement->close();
            }
        } catch (Throwable $e) {
            throw QueryException::fromThrowable($e, $query, $params);
        }
    }

    /**
     * @param array<int|string, mixed> $params
     * @param array<int|string, mixed> $types
     */
    public function fetchColumn(string $query, array $params = [], int $columnIndex = 0, array $types = []): mixed
    {
        if ($columnIndex < 0) {
            throw new \InvalidArgumentException('Column index cannot be negative.');
        }

        try {
            $statement = $this->prepareAndExecute($query, $params, $types);
            try {
                return $statement->field_count === 0
                    ? null
                    : $this->fetchColumnFromStatement($statement, $columnIndex);
            } finally {
                $statement->close();
            }
        } catch (Throwable $e) {
            throw QueryException::fromThrowable($e, $query, $params);
        }
    }

    public function getLastInsertId(): string
    {
        try {
            return (string) $this->mysqli->insert_id;
        } catch (Throwable $e) {
            throw new DatabaseException('Failed to get last insert id.', 0, $e);
        }
    }

    private function begin(): void
    {
        try {
            if (!$this->mysqli->begin_transaction()) {
                throw new TransactionException('Failed to begin transaction.');
            }
            $this->inTransaction = true;
        } catch (TransactionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new TransactionException('Failed to begin transaction.', 0, $e);
        }
    }

    private function commit(): void
    {
        try {
            if (!$this->mysqli->commit()) {
                throw new TransactionException('Failed to commit transaction.');
            }
            $this->inTransaction = false;
        } catch (TransactionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new TransactionException('Failed to commit transaction.', 0, $e);
        }
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function transaction(callable $callback): mixed
    {
        if ($this->inTransaction()) {
            throw new NestedTransactionException('Nested transactions are not supported by the driver.');
        }

        $this->begin();

        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Throwable $e) {
            $this->rollbackQuietly();
            throw $e;
        }
    }

    /**
     * @param array<int|string, mixed> $params
     * @param array<int|string, mixed> $types
     */
    private function prepareAndExecute(string $query, array $params, array $types): mysqli_stmt
    {
        $statement = $this->mysqli->prepare($query);
        if (!$statement instanceof mysqli_stmt) {
            throw new DatabaseException('Failed to prepare statement.');
        }

        try {
            if ($params !== []) {
                [$values, $typeString] = $this->normalizeParamsAndTypes($params, $types);
                $this->bindParams($statement, $typeString, $values);
            }

            if (!$statement->execute()) {
                throw new DatabaseException('Failed to execute statement.');
            }

            return $statement;
        } catch (Throwable $e) {
            $statement->close();
            throw $e;
        }
    }

    /**
     * @param array<int|string, mixed> $params
     * @param array<int|string, mixed> $types
     * @return array{0:list<mixed>,1:string}
     */
    private function normalizeParamsAndTypes(array $params, array $types): array
    {
        foreach (array_keys($params) as $key) {
            if (!is_int($key)) {
                throw new DatabaseException(
                    'MySQLi driver accepts positional parameters only. Bind named parameters before execution.'
                );
            }
        }

        ksort($params);
        $values = [];
        $typeString = '';

        foreach ($params as $key => $value) {
            $value = is_bool($value) ? (int) $value : $value;
            $values[] = $value;
            $typeString .= $this->resolveTypeChar($value, $types[$key] ?? null);
        }

        return [$values, $typeString];
    }

    private function resolveTypeChar(mixed $value, mixed $type): string
    {
        if (is_string($type) && trim($type) !== '') {
            return match (strtolower(trim($type))) {
                'i', 'int', 'integer', 'bool', 'boolean' => 'i',
                'd', 'double', 'float' => 'd',
                's', 'string', 'null', 'date', 'datetime' => 's',
                'b', 'blob', 'lob', 'binary' => 'b',
                default => throw new DatabaseException('Unsupported MySQLi parameter type.'),
            };
        }

        if ($type instanceof \UnitEnum) {
            return match (strtoupper($type->name)) {
                'INTEGER', 'BOOLEAN' => 'i',
                'FLOAT' => 'd',
                'LOB' => 'b',
                default => 's',
            };
        }

        return match (true) {
            is_int($value) => 'i',
            is_float($value) => 'd',
            is_resource($value) => 'b',
            default => 's',
        };
    }

    /** @param list<mixed> $values */
    private function bindParams(mysqli_stmt $statement, string $types, array $values): void
    {
        if (strlen($types) !== count($values)) {
            throw new DatabaseException('The number of bind types does not match the parameter count.');
        }

        $blobPayloads = [];
        for ($index = 0, $length = strlen($types); $index < $length; $index++) {
            if ($types[$index] === 'b') {
                $blobPayloads[$index] = $values[$index];
                $values[$index] = null;
            }
        }

        $arguments = [$types];
        foreach ($values as $index => $_) {
            $arguments[] = &$values[$index];
        }

        if (!call_user_func_array([$statement, 'bind_param'], $arguments)) {
            throw new DatabaseException('Failed to bind parameters.');
        }

        foreach ($blobPayloads as $index => $payload) {
            if ($payload === null) {
                continue;
            }

            if (is_resource($payload)) {
                while (!feof($payload)) {
                    $chunk = fread($payload, 8192);
                    if ($chunk === false) {
                        throw new DatabaseException('Failed to read blob stream.');
                    }
                    if ($chunk !== '') {
                        if (!$statement->send_long_data($index, $chunk)) {
                            throw new DatabaseException('Failed to send blob data.');
                        }
                    }
                }
                continue;
            }

            if (!$statement->send_long_data($index, (string) $payload)) {
                throw new DatabaseException('Failed to send blob data.');
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function fetchAllFromStatement(mysqli_stmt $statement): array
    {
        $result = $statement->get_result();
        if ($result instanceof mysqli_result) {
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();
            return $rows;
        }

        $metadata = $statement->result_metadata();
        if ($metadata === false) {
            return [];
        }

        $row = [];
        $bindings = [];
        foreach ($metadata->fetch_fields() as $field) {
            $row[$field->name] = null;
            $bindings[] = &$row[$field->name];
        }
        $metadata->free();

        if (!$statement->bind_result(...$bindings)) {
            throw new DatabaseException('Failed to bind result columns.');
        }

        $rows = [];
        while (true) {
            $fetched = $statement->fetch();
            if ($fetched === null) {
                break;
            }
            if ($fetched === false) {
                throw new DatabaseException('Failed to fetch a result row.');
            }
            $rows[] = array_map(static fn (mixed $value): mixed => $value, $row);
        }

        return $rows;
    }

    private function fetchColumnFromStatement(mysqli_stmt $statement, int $columnIndex): mixed
    {
        $result = $statement->get_result();
        if ($result instanceof mysqli_result) {
            $row = $result->fetch_row();
            $result->free();
            return is_array($row) ? ($row[$columnIndex] ?? null) : null;
        }

        $rows = $this->fetchAllFromStatement($statement);
        return $rows === [] ? null : (array_values($rows[0])[$columnIndex] ?? null);
    }

    private function rollbackQuietly(): void
    {
        try {
            if ($this->inTransaction) {
                $this->mysqli->rollback();
                $this->inTransaction = false;
            }
        } catch (Throwable) {
        }
    }
}
