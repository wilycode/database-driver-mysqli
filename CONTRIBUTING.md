# Contributing to WilyCode Database Driver MySQLi

Contributions, bug reports and focused pull requests are welcome.

## Development

- Use PHP 8.4+ and declare strict types in PHP sources.
- Maintain compatibility with `wilycode/database-driver` and the `wilycode/database-query` binder contract.
- Avoid adding framework dependencies or new public methods without a clear use case.
- Add regression tests for bug fixes. Database integration tests need a disposable MySQL database.
- Keep `README.md` examples and `CHANGELOG.md` current when user-visible behavior changes.

Run before a pull request:

```bash
composer validate --strict
composer install
composer test
```

GitHub Actions runs the suite with MySQL 8.4. If your change requires a running database, confirm the integration tests locally or in CI.

For cross-package changes, see the [WilyCode ecosystem](https://github.com/wilycode/wilycode) and submit changes to the appropriate independent repository.
