# Contributing to Mublo

**English** | [한국어](CONTRIBUTING.md)

Thank you for considering a contribution to Mublo. Bug reports, documentation corrections, architectural feedback, tests, and code changes are welcome.

## Before opening a pull request

1. Fork the repository and create a branch from `main`.
2. Keep the change focused on one problem or feature.
3. Add or update tests when behavior changes.
4. Update relevant documentation and `CHANGELOG.md` for user-visible changes.
5. Run the project quality gate.

```bash
composer install
composer check
```

The full check includes dependency-injection rules, extension API validation, static analysis, and PHPUnit tests.

Useful individual commands:

```bash
composer test
composer analyse
composer check-di
composer check-extension-api
```

## Development requirements

- PHP 8.2 or later
- MySQL 5.7.8 or later, or MariaDB 10.3 or later
- Composer
- required PHP extensions listed in the [installation guide](docs/user-guide/installation.en.md)

## Project conventions

- Classes use `PascalCase`.
- Methods use `camelCase`.
- Controllers should not contain business logic.
- Services return `Result` objects for application operations.
- Packages and Plugins should use documented Events, Contracts, Providers, and extension APIs instead of reaching into unstable Core internals.
- Existing commit messages use concise conventional prefixes such as `feat:`, `fix:`, `docs:`, `test:`, and `refactor:`.

## Pull requests

Describe:

- what changed;
- why it changed;
- how the change was verified;
- any compatibility or migration impact.

The repository includes a pull request template. CI must pass before a change can be merged.

## Reporting issues

Use GitHub Issues for reproducible bugs, feature proposals, and documentation problems. Include your PHP version, database and version, web server, operating system, and Mublo version when reporting a bug.

Do not report security vulnerabilities in a public issue. Follow the [security policy](SECURITY.en.md) instead.

## Documentation language

Most detailed documentation currently uses Korean. English corrections and translations are welcome. A partial, accurate translation is preferable to an unreviewed machine translation of the entire documentation set.
