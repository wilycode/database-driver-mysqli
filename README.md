# WilyCode Database Driver MySQLi

[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Tests](https://github.com/wilycode/database-driver-mysqli/actions/workflows/tests.yml/badge.svg)](https://github.com/wilycode/database-driver-mysqli/actions/workflows/tests.yml)

**A lightweight MySQLi database driver and positional parameter binder for PHP 8.4+.** Implements WilyCode's shared database contract and works with compiled queries from WilyCode Database Query.

> **WilyCode PHP Ecosystem**
>
> Part of **[WilyCode](https://github.com/wilycode/wilycode)** — independent PHP packages with their own repositories, releases, and Composer installations.
>
> **[Explore the WilyCode PHP ecosystem →](https://github.com/wilycode/wilycode)**

## Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Connecting](#connecting)
- [Executing queries](#executing-queries)
- [Reading data](#reading-data)
- [Binding compiled queries](#binding-compiled-queries)
- [Parameter types](#parameter-types)
- [Transactions](#transactions)
- [Connection options](#connection-options)
- [Error handling](#error-handling)
- [Limitations and security](#limitations-and-security)
- [Testing and development](#testing-and-development)
- [Related packages](#related-packages)
- [License](#license)

## Features

- Implements `WilyCode\Database\Driver\DatabaseInterface`.
- MySQL and MariaDB connections through PHP's MySQLi extension.
- Prepared statements with **positional `?` parameters**.
- Named-to-positional binding through `MysqliBinder` for canonical `CompiledQuery` objects.
- Read one row, multiple rows, or a single column; insert, update, and delete records.
- Explicit type binding (`i`, `d`, `s`, `b`), including streaming LOB parameters.
- Transaction callbacks with commit, rollback on failure, and strict rejection of nested transactions.
- Shared WilyCode database exception types.

This package is a **database driver**, not a SQL query builder or ORM. It does not implement automatic query execution from `Query` builders; use `compile()` and `MysqliBinder` as shown below.

## Requirements

- PHP **8.4+** with the `mysqli` extension.
- A compatible MySQL or MariaDB server for real database operations.
- `wilycode/database-driver:^0.1` — shared interface and exceptions.
- `wilycode/database-query:^0.1` — canonical compiled queries and binder contract.

Composer installs the two WilyCode dependencies automatically.

## Installation

```bash
composer require wilycode/database-driver-mysqli
```

## Connecting

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use WilyCode\Database\DriverMysqli\MysqliDatabase;

$db = MysqliDatabase::connect(
    host: '127.0.0.1',
    username: getenv('DB_USER') ?: 'app',
    password: getenv('DB_PASSWORD') ?: '',
    database: 'app',
    port: 3306,
    options: ['charset' => 'utf8mb4'],
);
```

You can also wrap an existing `mysqli` connection:

```php
$connection = new mysqli('127.0.0.1', 'app', getenv('DB_PASSWORD') ?: '', 'app');
$db = new MysqliDatabase($connection);
```

**Use a dedicated, least-privileged database user in applications.** The provided connection is owned by the calling code; the adapter does not expose a separate `close()` method.

## Executing queries

`execute()` returns the number of affected rows for write statements:

```php
$inserted = $db->execute(
    'INSERT INTO users (email, active) VALUES (?, ?)',
    ['hello@example.test', true],
);

$id = $db->getLastInsertId(); // String ID, for example "15".

$updated = $db->execute(
    'UPDATE users SET active = ? WHERE id = ?',
    [false, (int) $id],
);

$deleted = $db->execute(
    'DELETE FROM users WHERE id = ?',
    [(int) $id],
);
```

**The raw MySQLi driver accepts positional parameters only.** A SQL string containing named `:parameters` must first be converted by `MysqliBinder` (see below). `execute()` is designed for write/DDL statements, not for fetching SELECT results.

## Reading data

```php
$user = $db->fetch(
    'SELECT id, email FROM users WHERE id = ?',
    [15],
);
// Associative array, or [] if no row was found.

$users = $db->fetchAll(
    'SELECT id, email FROM users WHERE active = ?',
    [1],
);
// List of associative arrays, or [] when empty.

$count = $db->fetchColumn('SELECT COUNT(*) FROM users');
// First column of the first row, or null if no row was found.

$email = $db->fetchColumn(
    'SELECT id, email FROM users WHERE id = ?',
    [15],
    columnIndex: 1,
);
```

`fetchColumn()` uses a **zero-based column index**. Passing a negative index throws `InvalidArgumentException`.

## Binding compiled queries

WilyCode Database Query produces canonical **named placeholders**. MySQLi expects `?` placeholders, so the binder translates the SQL, values, and types:

```php
use WilyCode\Database\DriverMysqli\MysqliBinder;
use WilyCode\Database\Query\Query;

$query = Query::select('users', 'u')
    ->columns(['id' => 'id', 'email' => 'email'])
    ->where('u.active', true)
    ->limit(10);

$compiled = $query->compile();
$bound = (new MysqliBinder())->bind($compiled);

$users = $db->fetchAll(
    $bound->sql,
    $bound->params,
    $bound->types,
);
```

Example conversion (placeholder names depend on the compiled query):

```sql
-- Compiled SQL
SELECT * FROM users WHERE id = :user_id AND active = :active

-- SQL after MysqliBinder
SELECT * FROM users WHERE id = ? AND active = ?
```

The binder preserves parameter order and ignores placeholder-like text within SQL strings, comments, and quoted identifiers. It does not execute SQL or open database connections.

## Parameter types

When calling `execute()`, `fetch()`, `fetchAll()`, or `fetchColumn()` directly, optional `$types` entries may specify MySQLi binding type characters:

| Type | MySQLi character | Example |
| --- | --- | --- |
| Integer / boolean | `i` | `10`, `true` |
| Floating-point | `d` | `1.25` |
| String / null | `s` | `'hello'`, `null` |
| Binary / LOB | `b` | Binary string or readable stream |

```php
$db->execute(
    'INSERT INTO samples (amount, note) VALUES (?, ?)',
    [1.25, 'Example'],
    ['d', 's'],
);
```

The driver also accepts common descriptive type names such as `integer`, `boolean`, `float`, `string`, and `lob`. Unrecognized explicit type names are rejected rather than guessed. Without explicit types, the driver infers common types from PHP values; booleans are normalized to `0`/`1`. For a `CompiledQuery`, `MysqliBinder` maps `ParameterType` values to MySQLi characters and supplies the resulting `$bound->types` array.

Binary streams are read and passed through `send_long_data()` when bound as `b`.

## Transactions

`transaction()` commits on success and attempts a rollback if the callback throws:

```php
$db->transaction(function (MysqliDatabase $tx): void {
    $tx->execute(
        'INSERT INTO users (email) VALUES (?)',
        ['one@example.test'],
    );

    $tx->execute(
        'INSERT INTO users (email) VALUES (?)',
        ['two@example.test'],
    );
});
```

The callback receives the current `MysqliDatabase` instance and may return a value:

```php
$createdId = $db->transaction(function (MysqliDatabase $tx): string {
    $tx->execute('INSERT INTO users (email) VALUES (?)', ['new@example.test']);

    return $tx->getLastInsertId();
});
```

`inTransaction()` indicates whether the adapter has started an active transaction. A nested call to `transaction()` throws `NestedTransactionException` rather than opening a savepoint. Avoid MySQL DDL statements within these callbacks: MySQL may implicitly commit transactions around DDL.

## Connection options

`MysqliDatabase::connect()` accepts optional connection arguments:

```php
$db = MysqliDatabase::connect(
    host: '127.0.0.1',
    username: 'app',
    password: getenv('DB_PASSWORD') ?: '',
    database: 'app',
    port: 3306,
    socket: null,
    options: [
        'charset' => 'utf8mb4',
        'connect_timeout' => 3,
        'flags' => 0,
        'report_mode' => MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT,
    ],
);
```

`charset` defaults to `utf8mb4`; `connect_timeout` defaults to 3 seconds. `report_mode` is passed to PHP's `mysqli_report()`, which **changes MySQLi's global reporting setting for the process**. Use it carefully if other parts of your application also use MySQLi.

## Error handling

Connection problems raise `ConnectionException`; statement preparation, binding, or execution failures raise `QueryException`; transaction operation failures raise `TransactionException`.

```php
use WilyCode\Database\Driver\Exception\ConnectionException;
use WilyCode\Database\Driver\Exception\QueryException;

try {
    $db->execute('INSERT INTO users (email) VALUES (?)', ['hello@example.test']);
} catch (QueryException $exception) {
    error_log($exception->getMessage());
}
```

`QueryException` includes the SQL text and **parameter keys**, not parameter values. SQL text and nested driver exceptions can still contain sensitive information. Do not dump exception details to public responses.

## Limitations and security

- MySQLi is for MySQL-compatible databases; use [`wilycode/database-driver-pdo`](https://github.com/wilycode/database-driver-pdo) if you need PDO's driver ecosystem.
- Queries with interpolated SQL **identifiers** (table/column names) are not protected by prepared statements. Validate identifiers or use trusted constants.
- MySQLi accepts positional bindings. Use `MysqliBinder` for canonical named queries.
- Nested transactions are not supported. Transactions require a transactional storage engine such as InnoDB.
- Avoid combining `execute()` with SELECT statements; use `fetch()`/`fetchAll()` instead.
- A SELECT with multiple columns named identically yields an associative row where duplicate names may overwrite one another; use explicit column aliases in JOINs.

## Testing and development

```bash
composer install
composer validate --strict
composer test
```

GitHub Actions runs PHPUnit on **PHP 8.4 with a MySQL 8.4 service**. Integration tests use these environment variables:

| Variable | Meaning |
| --- | --- |
| `MYSQL_TEST_HOST` | Server hostname |
| `MYSQL_TEST_PORT` | Server port (default `3306`) |
| `MYSQL_TEST_USER` | Test database user |
| `MYSQL_TEST_PASS` | Test database password |
| `MYSQL_TEST_DB` | Existing writable test database |

When these variables are not provided, database integration tests are skipped; binder tests still run. Use a **disposable test database**: integration tests create and delete temporary tables. The full suite requires the PHP `mysqli` extension.

Test files remain in Git but are excluded from Composer distribution archives.

## Related packages

| Package | Purpose |
| --- | --- |
| [wilycode/database-driver](https://github.com/wilycode/database-driver) | Database interface, transactions, and shared exceptions. |
| [wilycode/database-query](https://github.com/wilycode/database-query) | Driver-independent SQL query builder and compiled query types. |
| [wilycode/database-driver-pdo](https://github.com/wilycode/database-driver-pdo) | Alternate PDO-based database driver. |
| [wilycode/database-query-executor](https://github.com/wilycode/database-query-executor) | Higher-level execution of compiled queries. |

## Contributing

Issues and pull requests are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) and [GitHub Issues](https://github.com/wilycode/database-driver-mysqli/issues).

## License

MIT © 2026 Simon Piter. See [LICENSE](LICENSE).
