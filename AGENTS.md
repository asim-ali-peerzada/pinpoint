# Pinpoint — Agent Instructions

## Testing mandate (non-negotiable)

Before and after **every change** to this codebase (code, config, migrations,
docs, or tests), run the **complete** test suite and the quality gates:

```bash
composer test          # full Pest suite — nothing may break
vendor/bin/pint        # style
composer analyse       # PHPStan level 5
```

Never ship a change with a failing or skipped test. If a change intentionally
alters behavior, the test suite must be updated in the same change.

Test strategy follows `docs/testing-plan.md` (adversarial pre-release QA
mandate). When adding or modifying features, add tests that cover:

- happy path AND boundary (threshold − 1 / threshold / threshold + 1)
- false-positive and false-negative cases (e.g. exact duplicates must never
  be classified as N+1)
- empty state (every command with zero data)
- JSON contract (valid JSON, no ANSI, stable types)
- privacy (raw query bindings must never be persisted — only hashes)
- route identity (named/unnamed, parameterized paths, HTTP methods)
- every discovered bug becomes a permanent regression test;
  never fix a bug without its regression test
- existing fixtures/routes in `tests/TestCase.php` are reused; extend them
  additively, never break other suites