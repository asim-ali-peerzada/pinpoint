# Contributing

Contributions are welcome. Please open an issue first to discuss changes before opening a pull request.

## Reporting a bug or requesting a feature

Use the issue templates — they ask for the environment details (Laravel version, PHP version, database) and reproduction steps that make a report actionable. Bug reports without those details may be closed as incomplete.

## Development

```bash
composer install
composer test        # run the Pest suite
composer analyse     # Larastan level 5
composer format      # Pint (fixes style)
composer format:test # Pint (check only)
```

## Pull requests

Open a PR against `main`. The PR template includes the pre-merge checklist (tests, Larastan, Pint, CHANGELOG); CI runs the full PHP × Laravel matrix on every PR.

## Release checklist

Before tagging a release: CI matrix green across supported PHP/Laravel versions, Pint + Larastan clean, CHANGELOG updated, version bumped per SemVer (breaking changes = major bump, no exceptions).