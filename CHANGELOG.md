# Changelog

All notable changes to WilyCode Database Driver MySQLi are documented here.

## [Unreleased]

### Changed

- Renamed the Composer package to `wilycode/database-mysqli`.
- Switched the shared contracts dependency to `wilycode/database`.
- Retained the existing `WilyCode\Database\DriverMysqli\` PHP namespace.
- Declared `replace` for `wilycode/database-driver-mysqli` at matching versions.

## [0.1.0] - 2026-10-13

### Added

- Initial public MySQLi implementation of the `DatabaseInterface` contract.
- Positional query parameters, native result fetching, and transaction callbacks.
- `MysqliBinder` for converting canonical named queries to MySQLi bindings.
- PHPUnit unit tests and automated MySQL 8.4 integration tests in GitHub Actions.
- Installation, usage, and development documentation.

### Fixed

- Detect unsuccessful connection, charset configuration, statement execution, and LOB transfer when MySQLi exception reporting is disabled.
- Release prepared statements when an execution or read fails.
- Reject unrecognized explicit parameter types rather than silently inferring from the first character.
