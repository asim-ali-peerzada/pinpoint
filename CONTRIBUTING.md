# Contributing

Contributions are welcome. Please open an issue first to discuss changes before opening a pull request.

## Development

```bash
composer install
composer test        # run the Pest suite
composer analyse     # Larastan level 5
composer format      # Pint (fixes style)
composer format:test # Pint (check only)
```

## Release checklist

See the package rules in `AGENTS.md` — CI green across the matrix, Pint + Larastan clean, CHANGELOG updated, version bumped per SemVer.