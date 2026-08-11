# Contributing to Preauth

Thank you for your interest in contributing to Preauth! This document
outlines the process for contributing to the project.

## Development Setup

1. Clone the repository
2. Install dependencies: `composer install`
3. Copy `.env.example` to `.env` and configure as needed
4. Run tests: `vendor/bin/phpunit`

## Code Style

This project follows [PSR-12](https://www.php-fig.org/psr/psr-12/) and
includes `php-cs-fixer` as a dev dependency.

```bash
# Check for style violations
vendor/bin/php-cs-fixer fix --dry-run --diff

# Auto-fix
vendor/bin/php-cs-fixer fix
```

All code must pass the style check before it can be merged.

## Testing

All code changes must include tests. The project maintains 100% code
coverage — new code must be fully tested.

```bash
# Run tests
vendor/bin/phpunit

# Run with coverage (requires Xdebug)
XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text
```

### Test Structure

- **Unit tests** go in `tests/Unit/` and mirror the `src/` directory structure
- **Functional tests** go in `tests/Functional/` and test the full HTTP kernel
- Use the support traits (`TotpTestHelper`, `ListenerTestHelper`) for
  reusable test fixtures

## Pull Request Process

1. Create a feature branch from `main`
2. Make your changes, ensuring tests pass and code style is clean
3. Update documentation if needed (README, CHANGELOG, docs/)
4. Submit a pull request to `main`

### Commit Messages

Use conventional commit format:

- `feat:` new feature
- `fix:` bug fix
- `docs:` documentation only
- `refactor:` code change that neither fixes a bug nor adds a feature
- `test:` adding or correcting tests
- `chore:` build process, tooling, etc.

## Architecture

Preauth is an event-listener-driven Symfony application (no controllers).
See `ROADMAP.md` for the full architecture overview and design decisions.

## License

By contributing, you agree that your contributions will be licensed under
the MIT License.
