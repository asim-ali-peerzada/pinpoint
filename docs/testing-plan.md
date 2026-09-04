# Pinpoint — Pre-Release Adversarial Testing & QA Mandate

## Objective

You are acting as a senior Laravel package maintainer, QA engineer, performance engineer, and adversarial test engineer.

Your task is to  **thoroughly test the Pinpoint package inside this repository before it is released/promoted to real Laravel developers** .

Do not assume that existing tests mean the package is production-ready.

Do not merely add tests that confirm the happy path.

Your objective is to discover:

* incorrect behavior
* false positives
* false negatives
* data corruption
* lifecycle bugs
* aggregation bugs
* concurrency problems
* Laravel compatibility issues
* CLI failures
* configuration failures
* JSON contract problems
* performance regressions
* security/privacy problems
* edge cases that real Laravel applications will expose

The standard is:

> **Can a real Laravel developer install this package into an existing application and trust its results?**

Do not optimize for test-count or code coverage percentage. Optimize for  **behavioral correctness and developer trust** .

---

# 1. FIRST: Understand the Package Before Changing Anything

Before writing or modifying tests:

1. Inspect the entire repository.
2. Read:
   * `composer.json`
   * `README.md`
   * package configuration
   * service providers
   * middleware
   * event listeners
   * database migrations
   * models
   * repositories/services
   * query listeners
   * aggregation logic
   * N+1 detection logic
   * duplicate/CACHE detection logic
   * route identification/grouping
   * memory tracking
   * CLI commands
   * JSON serialization
   * CI/check logic
   * pruning/reset logic
   * existing test suite
3. Determine:
   * supported PHP versions
   * supported Laravel versions
   * supported database drivers
   * package lifecycle
   * whether recording is HTTP-only
   * how request state is stored
   * how query state is stored
   * how route names are normalized
   * how statistics are calculated
   * how data is persisted
4. Do not invent behavior that the package does not claim to support.
5. Do not modify production code merely to make tests pass.
6. If the implementation and documentation disagree, report the discrepancy.

Create a short internal test plan based on the actual architecture before implementing tests.

---

# 2. Establish a Baseline

Before changing anything:

Run the existing test suite.

Record:

* test count
* passing tests
* failing tests
* skipped tests
* warnings
* PHP version
* Laravel version
* database driver
* code coverage if available

Then inspect whether the current tests actually exercise the important functionality.

Do not assume:

> "The current tests pass, therefore the package works."

Identify untested areas.

---

# 3. Test Suite Structure

Organize tests logically rather than creating one enormous test file.

Prefer categories such as:

```text
tests/
├── Unit/
│   ├── QueryFingerprintTest.php
│   ├── QueryNormalizationTest.php
│   ├── NPlusOneDetectorTest.php
│   ├── DuplicateQueryDetectorTest.php
│   ├── StatisticsTest.php
│   ├── RouteNormalizerTest.php
│   ├── HealthClassifierTest.php
│   └── MemoryTest.php
│
├── Feature/
│   ├── RequestRecordingTest.php
│   ├── ExceptionHandlingTest.php
│   ├── RouteAggregationTest.php
│   ├── JsonOutputTest.php
│   ├── ReportCommandTest.php
│   ├── CheckCommandTest.php
│   ├── AggregateCommandTest.php
│   ├── PruneCommandTest.php
│   ├── ResetCommandTest.php
│   ├── ConfigurationTest.php
│   └── DisabledModeTest.php
│
└── Integration/
    ├── EloquentRelationshipTest.php
    ├── MultipleDatabaseConnectionTest.php
    ├── ConcurrentRequestTest.php
    └── RealApplicationScenarioTest.php
```

Adapt this structure to the repository rather than blindly creating these exact files.

---

# 4. N+1 Detection — Core Test Matrix

Test obvious N+1:

```php
$families = Family::all();

foreach ($families as $family) {
    $family->people;
}
```

Expected:

```text
N+1 detected
repeat count corresponds to actual repeated queries
correct route
correct caller
```

Test eager loading:

```php
$families = Family::with('people')->get();
```

Expected:

```text
No N+1
```

Test nested N+1:

```text
Family
 → people
 → family
 → memories
```

Expected:

```text
Nested N+1 correctly detected
```

Test nested eager loading:

```php
Family::with([
    'people.family.memories'
])->get();
```

Expected:

```text
No false N+1
```

---

# 5. N+1 False Positive Tests

Aggressively test cases where repeated queries are legitimate.

Examples:

```php
DB::select('select * from families where id = ?', [1]);
DB::select('select * from families where id = ?', [1]);
DB::select('select * from families where id = ?', [1]);
```

This should be classified according to the package's documented duplicate/CACHE semantics, NOT as N+1.

Test:

```php
DB::select('select * from families where id = ?', [1]);
DB::select('select * from families where id = ?', [2]);
DB::select('select * from families where id = ?', [3]);
```

Expected:

```text
N+1
```

Test mixed patterns:

```text
same query + same binding
same query + same binding
same query + different binding
same query + different binding
```

Verify that the package can distinguish the patterns correctly.

---

# 6. N+1 Boundary Testing

Test:

```text
0 repetitions
1 repetition
2 repetitions
3 repetitions
4 repetitions
5 repetitions
10 repetitions
50 repetitions
100 repetitions
1000 repetitions
```

Determine exactly when Pinpoint considers repeated behavior an N+1 anomaly.

Pay special attention to:

```text
N+1 x1
```

This must either:

1. be intentionally allowed and documented, or
2. never be classified as an N+1 anomaly.

Do not leave ambiguous behavior.

---

# 7. Query Fingerprinting

This is a high-priority test area.

Test SQL that differs only by:

* whitespace
* capitalization
* line breaks
* indentation
* comments if normalization supports them
* parameter values
* parameter types
* binding order
* number of bindings

Examples:

```sql
SELECT * FROM families WHERE id = ?
select * from families where id = ?
```

Determine whether they should have the same fingerprint according to implementation intent.

Test semantically different queries:

```sql
select * from families where id = ?
select * from families where name = ?
select name from families where id = ?
select * from families where id = ? limit 1
```

They must not be incorrectly collapsed.

---

# 8. Binding Edge Cases

Test bindings containing:

```text
null
0
1
-1
"0"
"1"
""
false
true
float values
dates
timestamps
UUIDs
long strings
unicode strings
special characters
JSON strings
binary-like values where supported
```

Verify that fingerprinting does not accidentally treat materially different bindings as identical.

Test arrays and variable-length `IN` queries:

```php
whereIn('id', [1, 2, 3])
whereIn('id', [4, 5, 6])
```

and:

```php
whereIn('id', [1, 2, 3])
whereIn('id', [1, 2, 3])
```

---

# 9. Duplicate Query / CACHE Classification

Verify the intended distinction:

```text
Same SQL + same bindings
        ↓
Duplicate/CACHE
```

versus:

```text
Same normalized SQL shape + varying bindings
        ↓
N+1
```

Test:

* exactly 2 duplicates
* 3 duplicates
* many duplicates
* duplicates mixed with normal queries
* duplicates mixed with N+1
* multiple duplicate patterns in one request
* duplicate queries across different routes
* duplicate queries across different connections

Ensure:

```text
duplicate != N+1
```

unless the documented classifier intentionally defines an overlap.

Verify:

* CLI report
* JSON output
* health classification
* summary count
* route detail
* Locate output

all agree with each other.

---

# 10. Multiple Query Patterns in One Request

Create a single request containing:

```text
normal query
N+1 pattern
duplicate/CACHE pattern
another normal query
another N+1 pattern
```

Verify that Pinpoint does not collapse all repeated queries into one category.

The report must retain independent classifications.

---

# 11. Query Ordering

Test queries occurring:

```text
before relationship access
after relationship access
inside loops
inside nested loops
inside conditionals
inside callbacks
inside collections
inside resources
inside transformers
inside services
inside model accessors where applicable
```

Verify that caller information remains accurate.

---

# 12. Eloquent Relationship Coverage

Test:

* `belongsTo`
* `hasOne`
* `hasMany`
* `belongsToMany`
* `morphOne`
* `morphMany`
* polymorphic relationships if supported by the application
* nested relationships
* conditional relationship loading
* lazy loading
* eager loading
* constrained eager loading
* `load()`
* `loadMissing()`
* relationship existence queries
* relationship counts

Examples:

```php
$families->load('people');
```

```php
$families->loadMissing('people');
```

```php
Family::with('people')->get();
```

Verify no false positives.

---

# 13. Route Identification

Test:

```text
named routes
unnamed routes
closure routes
controller routes
invokable controllers
route groups
route prefixes
route parameters
nested route parameters
optional parameters
resource routes
API routes
web routes
middleware routes
```

For:

```text
/families/1
/families/2
/families/3
```

verify whether Pinpoint intentionally aggregates them as:

```text
families/{family}
```

rather than incorrectly creating independent metrics for every ID.

Test HTTP methods:

```text
GET
POST
PUT
PATCH
DELETE
OPTIONS
HEAD
```

Verify whether method is intentionally part of route identity.

---

# 14. Request Lifecycle

Test recording through:

* normal successful request
* validation failure
* authentication failure
* authorization failure
* 404
* 405
* redirect
* 500 exception
* middleware abort
* thrown exception
* JSON response
* HTML response
* streamed response if relevant
* empty response

Determine exactly which requests Pinpoint records and document any intentional exclusions.

---

# 15. Exception Safety

Create a route that performs queries and then throws:

```php
Family::count();

throw new RuntimeException('test');
```

Verify:

* request sample is not corrupted
* queries are not lost unexpectedly
* request state is cleaned up
* subsequent requests work correctly
* exception handling does not leak state into the next request

Run several successful requests before and after the exception.

---

# 16. Request State Isolation

Test:

```text
request A
request B
request C
```

where each performs different queries.

Verify:

```text
A's queries never appear in B
B's queries never appear in C
```

This becomes especially important if the package uses:

* static properties
* singleton services
* global arrays
* cached state

---

# 17. Concurrency

Run many requests concurrently.

Example:

```bash
seq 1 100 | xargs -n1 -P10 \
  curl -s -o /dev/null http://127.0.0.1:8990/api/n-plus-one
```

Then inspect:

```text
samples
query counts
route aggregation
duplicate counts
N+1 counts
memory
```

Expected:

```text
100 samples
```

or whatever exact count is expected under the application's recording configuration.

Look for:

* lost samples
* duplicate samples
* corrupted rows
* race conditions
* incorrect aggregation
* cross-request state contamination

---

# 18. Statistics Correctness

Test p50/p95/p99/average independently.

Do not trust the implementation simply because values look reasonable.

Use known values such as:

```text
1
2
3
4
5
6
7
8
9
10
```

and calculate expected values independently.

Test:

```text
1 sample
2 samples
3 samples
10 samples
100 samples
101 samples
```

Test extreme outliers:

```text
50ms repeated 99 times
2000ms once
```

Verify that:

* average changes appropriately
* p50 remains appropriate
* p95 behaves correctly
* p99 behaves correctly
* no integer/rounding errors occur

Verify percentile methodology is intentional and documented.

---

# 19. Timing Edge Cases

Test:

* extremely fast requests
* sub-millisecond operations if measurable
* 1ms
* 10ms
* 100ms
* 250ms
* threshold boundary
* just below threshold
* exactly threshold
* just above threshold
* multi-second requests

For every tier boundary test:

```text
threshold - 1
threshold
threshold + 1
```

Ensure classification is deterministic.

---

# 20. Memory Tracking

Test:

```text
normal memory
just below budget
exactly at budget
just above budget
large allocation
multiple allocations
temporary allocation
allocation then release
```

Verify that Pinpoint's use of peak memory behaves as documented.

Do not confuse:

```text
current memory
```

with:

```text
peak memory
```

Test multiple requests and ensure peak memory from one request doesn't contaminate another.

Test memory budget configuration.

---

# 21. Aggregation

Test multiple samples for the same route.

Example:

```text
route A: 100ms
route A: 200ms
route A: 300ms
```

Verify:

```text
samples = 3
avg = correct
p50 = correct
p95 = correct
p99 = correct
max/peak memory = correct
N+1 information = correct
duplicate information = correct
```

Then test multiple routes.

Ensure routes never merge accidentally.

---

# 22. Time Window / `--since`

Test:

```bash
php artisan pinpoint:report
php artisan pinpoint:report --since=1m
php artisan pinpoint:report --since=5m
php artisan pinpoint:report --since=1h
```

Create old and new samples.

Verify the window includes exactly what it should.

Test:

* empty window
* boundary timestamp
* very large window
* invalid window
* future window if supported

---

# 23. JSON Output

Test:

```bash
php artisan pinpoint:report --json
```

Verify:

* valid JSON
* no ANSI escape codes
* no terminal formatting
* no warnings contaminating stdout
* no debug output contaminating stdout
* deterministic structure
* valid null values
* numeric fields remain numeric
* booleans remain booleans
* empty collections are valid JSON arrays
* no malformed output

Use an actual JSON parser to validate output.

Test:

```bash
php artisan pinpoint:report --json | jq .
```

The JSON output should be safe for:

* shell scripts
* CI
* AI agents
* IDE integrations
* other automation

Do not add an unnecessary second "AI JSON" format if the existing JSON can be made sufficiently diagnostic.

---

# 24. JSON Backward Compatibility

Treat the JSON structure as an API contract.

Check:

* field names
* types
* nullability
* nesting
* enum values

Avoid breaking changes without versioning/documentation.

If fields are added, ensure existing consumers can still parse the output.

---

# 25. CLI Commands

Test every command:

```bash
php artisan pinpoint:report
php artisan pinpoint:report --json
php artisan pinpoint:report --route=...
php artisan pinpoint:report --since=...

php artisan pinpoint:check
php artisan pinpoint:check --json

php artisan pinpoint:aggregate
php artisan pinpoint:prune
php artisan pinpoint:reset
```

Test:

* normal operation
* empty database
* large database
* invalid arguments
* missing route
* invalid route
* invalid time window
* invalid configuration
* repeated execution
* command execution immediately after reset
* command execution immediately after prune

---

# 26. CI Exit Codes

This is a release-critical area.

Test:

```text
No violations
→ exit 0

N+1 violation
→ non-zero exit

Query budget exceeded
→ non-zero exit

Duration budget exceeded
→ non-zero exit

Memory budget exceeded
→ expected exit behavior

Multiple violations
→ expected non-zero exit

Pinpoint internal error
→ must not silently report success
```

Test combinations of all available `--fail-*` and budget options.

Verify stdout/stderr behavior is appropriate for CI.

---

# 27. Configuration Testing

For every public configuration option:

Test:

```text
default
disabled
enabled
custom value
minimum value
maximum sensible value
boundary value
invalid value
```

Test configuration after:

```bash
php artisan config:cache
```

and:

```bash
php artisan config:clear
```

Ensure package behavior doesn't depend accidentally on uncached configuration.

---

# 28. Disabled Mode

Disable Pinpoint.

Then run a large number of requests.

Verify:

* no Pinpoint records created
* no unnecessary database writes
* no request-state leakage
* no exceptions
* application behavior unchanged
* minimal overhead

"Disabled" should actually mean disabled.

---

# 29. Database Storage Integrity

Inspect Pinpoint's storage after:

```text
1 request
10 requests
100 requests
1000 requests
```

Check:

* row counts
* foreign keys
* indexes
* orphaned records
* duplicate records
* failed writes
* transaction behavior
* database size

Test all supported database drivers.

At minimum, test the database drivers explicitly supported by the package.

---

# 30. Pruning

Generate old and new records.

Run:

```bash
php artisan pinpoint:prune
```

Verify:

* records older than retention policy are removed
* recent records remain
* related records are correctly removed
* no orphan records remain
* report still works
* repeated prune is safe
* prune on empty storage is safe

---

# 31. Reset

Run:

```bash
php artisan pinpoint:reset
```

Verify:

```text
all Pinpoint data is gone
```

Then immediately:

```bash
php artisan pinpoint:report
```

Expected:

```text
empty report / zero routes
```

Then generate new data and verify Pinpoint starts cleanly again.

---

# 32. Aggregate Command

Test:

```bash
php artisan pinpoint:aggregate
```

before and after multiple samples.

Verify:

* no double aggregation
* no data loss
* no duplicated aggregates
* correct statistics
* idempotent behavior if the command is intended to be idempotent

Run the command twice and compare results.

---

# 33. Transactions

Test queries inside:

```php
DB::transaction(...)
```

including:

* successful transaction
* rollback
* nested transaction
* exception inside transaction

Pinpoint must not interfere with Laravel transaction behavior.

---

# 34. Multiple Database Connections

If supported:

```text
mysql
sqlite
pgsql
```

or the connections declared by the package/application.

Test:

```text
same SQL on connection A
same SQL on connection B
```

Ensure they are not incorrectly treated as the same query pattern if connection identity matters.

Test query counts across connections.

---

# 35. Query Types

Test:

```text
SELECT
INSERT
UPDATE
DELETE
raw queries
Eloquent queries
query builder queries
transactions
aggregate queries
exists queries
count queries
upsert
bulk insert
bulk update
```

Determine which are intentionally recorded.

Verify timing and query counting.

---

# 36. Sensitive Data / Privacy

This is release-critical.

Inspect everything Pinpoint stores and outputs.

Determine whether it stores:

* SQL
* bindings
* route names
* URLs
* request data
* headers
* authentication data
* tokens
* passwords
* emails
* personal information

Ensure secrets are not accidentally persisted.

Test queries such as:

```sql
select * from users where email = ? and password = ?
```

with sensitive bindings.

Determine whether bindings are stored, redacted, hashed, omitted, or otherwise protected.

The README/documentation must accurately describe this behavior.

Do not expose sensitive values in:

```text
CLI
JSON
database
logs
CI artifacts
```

---

# 37. Caller / Source-Line Detection

Test queries originating from:

```text
routes
controllers
services
repositories
models
resources
closures
anonymous functions
vendor code
framework code
nested calls
callbacks
```

Verify that Pinpoint selects the correct application caller rather than an irrelevant framework/vendor frame.

Test that:

```text
file
line
```

remain correct after code movement.

---

# 38. Clickable Terminal Links

If the package supports OSC 8 hyperlinks:

Test:

* VS Code
* PhpStorm
* Cursor
* configured editor
* default editor
* invalid editor configuration
* spaces in file paths
* non-existent file
* Windows-style paths if relevant
* Linux paths

Ensure plain terminal output remains usable if hyperlink support is unavailable.

---

# 39. Real Laravel API Resource Scenario

Create a realistic API endpoint:

```text
Controller
 → Eloquent models
 → relationships
 → API Resource
 → JSON serialization
```

Test:

1. correct eager loading
2. missing eager loading
3. nested relationships
4. resource-level relationship access
5. conditional relationships

This is important because real Laravel N+1 bugs often occur inside resources/transformers rather than obvious controller loops.

---

# 40. Real Laravel Application Scenario

Do not rely exclusively on the demo application.

Install Pinpoint into at least one realistic Laravel application.

Preferably test:

1. CRUD application
2. API-heavy application
3. relationship-heavy application
4. an existing production-like application if available

Do not engineer the application specifically to make Pinpoint pass.

Let Pinpoint observe normal application behavior.

Look for:

* false positives
* missed N+1
* strange routes
* incorrect caller locations
* incorrect memory
* incorrect query counts
* unexpected storage growth
* unexpected overhead

---

# 41. Laravel Lifecycle Compatibility

Test Pinpoint under:

* normal Laravel HTTP lifecycle
* middleware
* exception handler
* route middleware
* authentication middleware
* validation middleware
* termination middleware
* queued/after-response work if relevant

Verify request cleanup occurs reliably.

---

# 42. Long-Running Process Testing

If the package claims compatibility with long-running workers or Laravel Octane, test it.

If it does NOT support Octane/long-running workers, determine whether documentation clearly says so.

For long-running processes test:

```text
request A
request B
request C
...
request 1000
```

Look for:

* memory leaks
* stale request state
* cumulative query counters
* cross-request contamination
* increasing latency
* increasing Pinpoint storage overhead

---

# 43. Queue / CLI Isolation

Determine whether Pinpoint is HTTP-only.

If HTTP-only:

run jobs and Artisan commands and ensure they do not accidentally create HTTP performance samples.

If queue/CLI support exists, test those lifecycles separately.

---

# 44. Package Installation / Upgrade

Test from a fresh Laravel project.

Perform:

```text
composer require package
```

Then:

```text
php artisan migrate
```

and normal application startup.

Test:

* fresh installation
* existing database
* package upgrade
* migration rollback if supported
* config publishing
* config caching
* application with no existing Pinpoint data

Do not assume a package that works in the development repository installs correctly into a clean application.

---

# 45. Dependency Compatibility

Use the package's declared composer constraints.

Test the supported matrix:

```text
PHP versions
Laravel versions
database versions/drivers
```

where practical.

At minimum, run the complete test suite across every officially supported PHP/Laravel combination that the project claims to support.

Do not silently test only the developer's current environment.

---

# 46. Performance Overhead

Benchmark:

```text
Pinpoint disabled
vs
Pinpoint enabled
```

Test at least:

### No-query request

```text
baseline
```

### One-query request

```text
baseline
```

### Query-heavy request

```text
10 queries
50 queries
100 queries
500 queries
```

### N+1 request

```text
10 repeated queries
100 repeated queries
1000 repeated queries
```

Measure:

* request latency
* CPU if measurable
* memory
* database writes
* storage writes

Do not only report the best case.

Identify whether overhead grows linearly or unexpectedly.

---

# 47. Storage Performance

Measure the impact of:

```text
1 request
10 requests
100 requests
1000 requests
10000 requests
```

Determine:

* database growth
* insert cost
* aggregation cost
* report cost
* prune cost

The report must remain usable as data grows.

---

# 48. Empty-State Testing

Every command must behave sensibly with:

```text
zero routes
zero queries
zero samples
empty database
after reset
after prune
```

There must be no:

```text
division by zero
null errors
negative values
malformed tables
invalid JSON
```

---

# 49. Large-Data Testing

Generate:

```text
100 routes
1000 samples
10000 samples
100000 query records
```

where practical.

Test:

```text
report
JSON report
aggregate
prune
reset
```

Look for:

* timeouts
* memory exhaustion
* SQL inefficiency
* N+1 queries inside Pinpoint itself
* huge CLI output
* corrupted aggregation

---

# 50. Malformed / Corrupt Data

Where practical, insert invalid or incomplete Pinpoint records manually.

Then run:

```bash
php artisan pinpoint:report
php artisan pinpoint:aggregate
php artisan pinpoint:prune
```

Pinpoint should either:

* handle the data safely, or
* fail with a clear actionable error.

It must not silently produce believable but incorrect metrics.

---

# 51. Regression Testing

Every bug discovered during this audit must become a permanent regression test.

For each bug:

```text
reproduce
→ write failing test
→ fix implementation
→ confirm test passes
```

Never fix a bug without adding a regression test unless there is a strong documented reason.

---

# 52. Mutation Testing

Use deliberate mutations to verify that the tests can actually detect failures.

Examples:

Temporarily change:

```text
N+1 detection threshold
query fingerprint normalization
percentile calculation
route normalization
memory comparison
CI exit code
duplicate classification
```

The relevant tests should fail.

If a mutation does not cause tests to fail, the test suite is probably too weak in that area.

Do not leave intentional mutations in the final code.

---

# 53. Cross-Feature Interaction Testing

Do not test features only in isolation.

Test combinations such as:

```text
N+1 + high latency
N+1 + high memory
N+1 + duplicate queries
duplicate + high latency
memory + high latency
exception + N+1
exception + duplicate queries
route parameters + N+1
multiple database connections + duplicates
concurrency + N+1
concurrency + aggregation
prune + aggregation
reset + report
disabled mode + CLI
JSON + CI
```

The package must produce internally consistent results.

---

# 54. Report Consistency

For the same underlying data:

```bash
php artisan pinpoint:report
```

and:

```bash
php artisan pinpoint:report --json
```

must agree.

For a route:

```bash
php artisan pinpoint:report --route=...
```

must agree with the corresponding summary information.

The following must never contradict each other:

```text
CLI table
JSON
route drill-down
Locate
health
summary counts
CI checks
```

---

# 55. Documentation vs Reality

After testing, compare actual behavior with README claims.

For every major claim:

```text
README says X
Actual behavior = X
```

If not:

* fix implementation, or
* correct documentation.

Pay particular attention to:

* N+1 semantics
* duplicate/CACHE semantics
* memory tracking
* route grouping
* supported environments
* production/staging behavior
* privacy
* CI
* JSON
* performance overhead

---

# 56. Demo Application Validation

The existing Pinpoint demo application should remain a manual smoke-test environment.

Verify the following routes:

```text
/good
/acceptable
/needs-improvement
/critical
/n-plus-one
/nested-n1
/duplicate
/memory-hog
```

Expected behavior:

```text
good
→ GOOD / healthy

acceptable
→ ACCEPTABLE

needs-improvement
→ NEEDS_IMPROVEMENT

critical
→ CRITICAL

n-plus-one
→ N+1

nested-n1
→ nested N+1

duplicate
→ duplicate/CACHE
→ NOT N+1

memory-hog
→ memory budget violation
```

Do not modify the Pinpoint implementation simply to make the demo output look good.

The demo must reflect actual package behavior.

---

# 57. Final Release Gate

Do not declare the package ready merely because PHPUnit passes.

The final report must explicitly answer:

### Detection

* [ ] Correct N+1 detection
* [ ] Correct nested N+1 detection
* [ ] Correct duplicate/CACHE detection
* [ ] No obvious N+1 false positives
* [ ] No obvious N+1 false negatives
* [ ] `x1` behavior is intentional
* [ ] Eager loading does not trigger false positives

### Query analysis

* [ ] Query fingerprinting is correct
* [ ] Binding handling is correct
* [ ] NULL/type edge cases work
* [ ] Similar SQL is handled correctly
* [ ] Semantically different SQL is not collapsed
* [ ] Multiple patterns in one request work
* [ ] Multiple DB connections are handled correctly

### Performance metrics

* [ ] p50 mathematically correct
* [ ] p95 mathematically correct
* [ ] p99 mathematically correct
* [ ] average mathematically correct
* [ ] outliers behave correctly
* [ ] threshold boundaries behave correctly
* [ ] peak memory is correctly tracked
* [ ] memory budgets work

### Laravel lifecycle

* [ ] successful requests
* [ ] exceptions
* [ ] 404
* [ ] 405
* [ ] redirects
* [ ] validation failures
* [ ] authorization failures
* [ ] middleware aborts
* [ ] route parameters
* [ ] named/unnamed routes
* [ ] different HTTP methods
* [ ] Eloquent relationship types
* [ ] transactions

### Storage

* [ ] concurrent requests
* [ ] request isolation
* [ ] aggregation
* [ ] pruning
* [ ] reset
* [ ] empty state
* [ ] large data
* [ ] malformed data
* [ ] no orphan records

### CLI / automation

* [ ] report
* [ ] JSON
* [ ] route drill-down
* [ ] `--since`
* [ ] check
* [ ] check JSON
* [ ] aggregate
* [ ] prune
* [ ] reset
* [ ] invalid CLI arguments
* [ ] correct CI exit codes

### Agent / machine consumption

* [ ] JSON is valid
* [ ] JSON contains no terminal formatting
* [ ] JSON types are correct
* [ ] JSON schema is consistent
* [ ] diagnostic information is sufficient for automation
* [ ] no unnecessary duplicate "AI output" mode exists

### Security / privacy

* [ ] sensitive bindings are not unintentionally exposed
* [ ] passwords/tokens/secrets are not stored
* [ ] CLI does not leak sensitive information
* [ ] JSON does not leak sensitive information
* [ ] CI output does not leak sensitive information
* [ ] documentation accurately describes privacy behavior

### Performance

* [ ] disabled overhead measured
* [ ] enabled overhead measured
* [ ] query-heavy overhead measured
* [ ] N+1 overhead measured
* [ ] storage growth measured
* [ ] report performance measured
* [ ] prune performance measured

### Compatibility

* [ ] supported PHP versions
* [ ] supported Laravel versions
* [ ] supported database drivers
* [ ] clean installation tested
* [ ] upgrade path tested
* [ ] config cache tested

### Real-world validation

* [ ] tested against a realistic Laravel application
* [ ] tested against API/resource-heavy code
* [ ] tested against relationship-heavy code
* [ ] tested with concurrent requests
* [ ] tested with realistic data volumes

---

# 58. Bug Classification

For every discovered issue, classify it as:

```text
CRITICAL
HIGH
MEDIUM
LOW
```

Use these definitions:

### CRITICAL

Can cause:

* incorrect performance diagnosis at scale
* data corruption
* security/privacy exposure
* application failure
* CI reporting success when it should fail
* severe cross-request contamination

### HIGH

Significantly undermines a core Pinpoint feature:

* false N+1 detection
* missed N+1
* incorrect duplicate/CACHE classification
* incorrect percentile calculation
* incorrect route aggregation
* broken JSON
* broken pruning/reset
* major Laravel compatibility issue

### MEDIUM

Important but does not invalidate core functionality.

### LOW

Cosmetic, documentation, minor UX, or low-impact edge case.

---

# 59. Do Not Hide Problems

If you discover behavior that is questionable:

Do NOT:

* suppress the test
* weaken the assertion
* hardcode expected values without justification
* modify the demo to hide the issue
* classify a bug as expected without evidence
* remove a failing test because it is inconvenient
* change documentation to describe obviously broken behavior as intentional

Instead:

1. reproduce it
2. understand the root cause
3. determine intended behavior
4. add a regression test
5. fix the implementation if necessary
6. rerun the entire relevant suite

---

# 60. Final Deliverable

At the end, provide a detailed QA report containing:

## A. Environment

```text
PHP:
Laravel:
Database:
OS:
Pinpoint version:
```

## B. Existing test baseline

```text
Tests before:
Passing:
Failing:
Skipped:
```

## C. Tests added

List every new test file and what it validates.

## D. Test matrix

Show:

```text
Area | Tests | Passed | Failed | Status
```

## E. Bugs discovered

For every bug:

```text
ID
Severity
Area
Reproduction
Expected
Actual
Root cause
Fix
Regression test
```

## F. Remaining risks

Explicitly list anything that could not be validated.

Do not say "everything is good" if something remains untested.

## G. Final release recommendation

Return exactly one:

```text
RELEASE READY
```

or:

```text
RELEASE BLOCKED
```

If `RELEASE BLOCKED`, list the blockers first.

---

# Important Engineering Rule

Do not optimize this task for producing a large number of tests.

Optimize it for answering one question:

> **If an experienced Laravel developer installs Pinpoint into a real application tomorrow, what is the most likely way Pinpoint could lie to them, break their application, leak data, or become unusable?**

Find those failure modes before users do.
