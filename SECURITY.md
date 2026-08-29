# Security Policy

## Reporting a Vulnerability

Please report security vulnerabilities privately to `asimalipeerzada@gmail.com` — do not open a public issue.

Please include:

- Package version and how it was installed (composer path repo, Packagist, etc.)
- Laravel/PHP versions
- The affected component (collector, CLI, local API, storage)
- Steps to reproduce or a minimal proof of concept

You should receive a response within 7 days. If the issue is confirmed, a fix will be released (including a patch release if warranted) and the vulnerability disclosed after users have had time to update.

## Scope

Pinpoint is a local-first development tool; its local API is intentionally restricted to local/debug environments. Reports related to data stored by the package (raw SQL strings in `pinpoint_queries`) are considered in scope only insofar as the package's documented behavior increases exposure.