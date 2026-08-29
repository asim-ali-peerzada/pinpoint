# Sentinel — Implementation Blueprint

Honest framing up front: this is a real, multi-month side project if you build it properly, not a weekend package. I've broken it into phases so you can stop after any phase and still have something usable — the MVP (Phase 0–3) is genuinely shippable in a few weeks of part-time work, everything after that is where the actual differentiation (and actual effort) lives.

---

## 0. Naming & Scope Decision (do this first, 1 day)

Name locked in: **Sentinel** (composer: `asimali/sentinel`, command prefix: `sentinel:`, e.g. `php artisan sentinel:report`). One thing worth a second look before you register anything: the earlier message spelled it `sentinal` — that's likely a typo for `sentinel`, and this blueprint uses the corrected spelling throughout. Composer/npm/Marketplace names are painful to change after publishing, so confirm the spelling now rather than after `sentinel:report` ships to Packagist.

**Scope boundary — write this down and don't scope-creep past it for v1:**
- ✅ HTTP request/API performance (query count, query time, N+1 detection, total request time)
- ✅ Tiered categorization (good / acceptable / needs-improvement / critical)
- ✅ Artisan CLI report
- ✅ VS Code extension reading that data
- ❌ Queue job profiling (v2)
- ❌ Memory/CPU profiling (that's Blackfire's job — don't compete there)
- ❌ PhpStorm plugin (v2, only if VS Code version proves demand)
- ❌ Full APM (traces across services) — you are not building New Relic

---

## 1. Tech Stack

| Layer | Choice | Why |
|---|---|---|
| Collector | PHP package (Laravel Service Provider) | Lives in the app, hooks `DB::listen` + the `RequestHandled` event |
| Storage | MySQL/SQLite table via migration, OR piggyback on Laravel Pulse's storage | Pulse gives you retention/aggregation for free — see §3 |
| Aggregation | Scheduled artisan command (rolls raw entries into per-route stats) | Keeps the hot path (request lifecycle) cheap |
| Delivery to IDE | Local HTTP endpoint exposed by the package (`/_sentinel/api/*`, `local`/`debug`-only) | Extension polls or fetches on demand — no websocket complexity needed for v1 |
| IDE client | VS Code extension, TypeScript, Webview API | Largest Laravel-developer IDE install base; realistic scope for a solo build |

---

## 2. High-Level Architecture

```
[Laravel App]
   │
   ├─ DB::listen()  ──────────────► captures every query: SQL, bindings, time, caller
   ├─ handleLazyLoadingViolationUsing() ─► catches Eloquent relations lazy-loaded in a loop (model + relation name)
   ├─ RequestHandled event listener ─────► closes out the request: total time, query count, N+1 flags
   │                                  │
   │                                  ▼
   │                          [sentinel_requests table]
   │                          [sentinel_queries table]
   │
   ├─ scheduled `sentinel:aggregate` ──► computes p95/p50 per route, assigns tier, writes summary table
   │
   └─ local route group /_sentinel/api/* ──► serves JSON to any client (CLI, extension, browser)
                                                       │
                                                       ▼
                                            [VS Code Extension]
                                            fetches JSON, renders webview,
                                            "jump to code" opens file:line
```

Two tables, one aggregation job, one read API. Resist the urge to add more moving parts than that for v1.

---

## 3. Storage: build your own vs. ride on Pulse

**Recommendation: build your own two tables.** Pulse's storage is designed around its own recorder/card lifecycle and short retention windows (defaults to 24h buckets aimed at live dashboards) — you need per-route historical trend data and full query-level detail for the N+1 fingerprinting, which fights Pulse's model more than it saves you. Use Pulse for inspiration on its `Recorder` pattern (listen → buffer → flush), not as your datastore. This also keeps your package usable in projects that don't have Pulse installed at all — smaller dependency footprint, easier adoption.

**Schema:**

```php
Schema::create('sentinel_requests', function (Blueprint $table) {
    $table->id();
    $table->string('route_name')->nullable();
    $table->string('method', 10);
    $table->string('path');
    $table->unsignedInteger('duration_ms');
    $table->unsignedSmallInteger('query_count');
    $table->unsignedInteger('query_time_ms');
    $table->boolean('has_n_plus_one')->default(false);
    $table->timestamp('created_at');
    $table->index(['route_name', 'created_at']);
});

Schema::create('sentinel_queries', function (Blueprint $table) {
    $table->id();
    $table->foreignId('request_id')->constrained('sentinel_requests')->cascadeOnDelete();
    $table->string('sql_fingerprint', 64); // hashed, normalized SQL
    $table->text('sql');
    $table->unsignedInteger('time_ms');
    $table->string('caller_file')->nullable();
    $table->unsignedInteger('caller_line')->nullable();
    $table->timestamp('created_at');
});
```

Add a nightly/hourly `sentinel_summaries` table (route_name, p50, p95, p99, sample_count, tier, last_computed_at) — this is what both the CLI and the extension actually read from; raw tables are for drill-down only.

---

## 4. Phase 1 — Collector Core (1.5–2 weeks)

This is the part that has to be *fast* — it runs on every query, in production if enabled there. Budget matters more here than anywhere else in the project.

**Query listener:**

```php
DB::listen(function ($query) {
    if (! app(Sentinel::class)->isRecording()) return;

    $fingerprint = QueryFingerprinter::hash($query->sql);

    app(Sentinel::class)->recordQuery([
        'sql' => $query->sql,
        'fingerprint' => $fingerprint,
        'time_ms' => $query->time,
        'caller' => app()->isLocal() ? Caller::capture() : null, // expensive, gate it
    ]);
});
```

**N+1 fingerprinting — skip normalizing values, they're already gone.** `$query->sql` from `DB::listen` is the raw PDO-parameterized string — bound values are already `?` placeholders, never inlined literals. Running a regex to strip numbers/quoted-strings out of it does nothing useful and just burns CPU on every query. The one thing that genuinely varies between otherwise-identical queries is the *length* of an `IN (...)` list (a batch of 3 IDs vs. a batch of 8 IDs produces a different placeholder count), so that's the only normalization worth doing:

```php
class QueryFingerprinter
{
    public static function hash(string $sql): string
    {
        // Collapse IN (?, ?, ?, ...) lists of any length down to a single placeholder
        // so two calls to the same eager-load with different batch sizes still match.
        $normalized = preg_replace('/\?(,\s*\?)+/', '?', $sql);
        return hash('crc32', $normalized);
    }
}
```

**N+1 detection — two signals, not one:**

1. **Fingerprint repeat-count (structural signal):** if the same fingerprint appears **3+ times** in one request, flag it. Cheap, catches most cases, but only tells you *that* something repeated, not *what relation* caused it.
2. **Eloquent lazy-loading violation (semantic signal, register in your package's service provider):**

```php
Model::preventLazyLoading(! app()->isProduction());
Model::handleLazyLoadingViolationUsing(function ($model, $relation) {
    Sentinel::recordLazyLoad(get_class($model), $relation);
});
```

**Watch out — this is a single static callback, not a stack.** `handleLazyLoadingViolationUsing()` just overwrites `Model::$lazyLoadingViolationCallback`. If the app installing your package already has its own handler (e.g. reporting lazy-loading violations to Sentry/Bugsnag), whichever service provider's `boot()` runs last silently wins — either your package breaks their existing behavior, or their provider breaks your recording, depending on boot order you don't control. Don't ship the naive version above. Instead, auto-chain to whatever's already registered, and give people a manual escape hatch for the direction auto-chaining can't reach:

```php
// Auto-chain: captures whatever handler already exists at boot time via Reflection
// (there's no public getter for this property) and calls it after recording.
protected function registerLazyLoadingObserver(): void
{
    if (! config('sentinel.capture_lazy_loading_violations', true)) return;

    $existing = $this->currentLazyLoadingCallback();

    Model::handleLazyLoadingViolationUsing(function ($model, $relation) use ($existing) {
        Sentinel::recordLazyLoad(get_class($model), $relation);
        if ($existing) $existing($model, $relation); // preserve the app's own behavior
    });
}

protected function currentLazyLoadingCallback(): ?callable
{
    try {
        $prop = (new \ReflectionClass(Model::class))->getProperty('lazyLoadingViolationCallback');
        $prop->setAccessible(true);
        return $prop->getValue();
    } catch (\ReflectionException) {
        return null; // property renamed/removed in a future Laravel version — fail safe, don't break boot
    }
}
```

This only covers handlers that already existed *before* your package boots. If the app registers its own handler in a provider that boots *after* yours, they'll overwrite you instead — Reflection can't fix that direction, only cooperation can. So also expose a public `Sentinel::observeLazyLoad($model, $relation)` and document in the README: anyone with their own `handleLazyLoadingViolationUsing()` that boots after this package should just call that one line inside their own handler. And add `capture_lazy_loading_violations` to config so anyone who'd rather not risk touching their existing handler at all can disable this signal and fall back to the fingerprint-repeat-count heuristic alone.

Use the semantic signal as your primary N+1 detector when it's active, and keep the fingerprint-repeat-count as a fallback for N+1s that happen via raw query builder rather than Eloquent relations (which the lazy-loading hook won't catch either way). Don't over-engineer past these two — no loop-detection heuristics off caller-line proximity, that's complexity you don't need yet.

**`Caller::capture()`** — only run `debug_backtrace()` filtered to frames inside your app's `base_path()` when `app()->isLocal()` or a config flag is on, and always pass both flags: `debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15)`. `DEBUG_BACKTRACE_IGNORE_ARGS` avoids capturing full argument values at every stack frame (the actual source of the memory spikes), and the depth limit stops it walking the entire call stack when you only need the first frame inside your app code. This is the single most expensive thing this package does; get both flags wrong and you'll skew the very performance numbers you're trying to measure.

**Request-level listener** — hook `RequestHandled` instead of `terminate` middleware. It's the idiomatic Laravel way to observe "a request just finished" without adding a line to `Kernel.php` that someone can accidentally remove or scope to the wrong middleware group:

```php
Event::listen(RequestHandled::class, function (RequestHandled $event) {
    if (! $this->shouldRecord($event->request)) return;

    Sentinel::flush([
        'route_name' => $event->request->route()?->getName(),
        'method' => $event->request->method(),
        'path' => $event->request->path(),
        'duration_ms' => (microtime(true) - LARAVEL_START) * 1000,
    ]);
});
```

**Config, decide these before writing more code:**
```php
return [
    'enabled' => env('SENTINEL_ENABLED', app()->environment('local')),
    'sample_rate' => 1.0, // 1.0 = every request; drop to 0.1 in staging/prod
    'thresholds_ms' => ['good' => 150, 'acceptable' => 400, 'needs_improvement' => 1000],
    'route_threshold_overrides' => [
        // a fast autocomplete endpoint should alarm sooner than the default
        'api.search.autocomplete' => ['good' => 50, 'acceptable' => 120, 'needs_improvement' => 300],
        // a CSV export route is expected to be slow — don't let it flood the CRITICAL tier
        'api.reports.export' => ['good' => 1000, 'acceptable' => 3000, 'needs_improvement' => 8000],
    ],
    'n_plus_one_repeat_threshold' => 3,
    'capture_caller' => env('SENTINEL_CAPTURE_CALLER', true),
    'capture_lazy_loading_violations' => true, // disable if you have your own handleLazyLoadingViolationUsing() elsewhere
];
```

**Per-route overrides matter more than they look** — a blanket 400ms "acceptable" ceiling will misclassify a heavy export route as CRITICAL forever and bury a genuinely slow autocomplete endpoint in the "acceptable" noise. Without this, the tier list stops being trustworthy and people start ignoring it — exactly the alert-fatigue failure mode you're trying to avoid.

**Sampling matters if you ever want this safe to run outside local** — `sample_rate` lets someone run it at 10% in staging without doubling their DB write load.

---

## 5. Phase 2 — Aggregation & Categorization (1 week)

**Split this by environment — hourly aggregation alone breaks local dev.** A developer fixing a slow endpoint wants to see the tier change the moment they re-run the request, not up to an hour later. Worse, local environments usually don't run `schedule:run` on a cron at all, so `sentinel:aggregate` may simply never fire locally unless someone remembers `schedule:work` — the summary table just goes stale silently.

- **Local:** `sentinel:report` (Phase 3) reads directly from the raw `sentinel_requests` table and computes percentiles on-demand — dataset is small enough that this is instant, and it gives true immediate feedback.
- **Staging/production:** scheduled aggregation into the summary table, because raw-table volume there makes on-demand percentile computation too expensive to run per-request.

```php
// app/Console/Kernel.php — only meaningful once you're past local volume
$schedule->command('sentinel:aggregate')->hourly();
```

```php
class AggregateCommand extends Command
{
    public function handle()
    {
        DB::table('sentinel_requests')
            ->select('route_name')
            ->selectRaw('COUNT(*) as sample_count')
            ->selectRaw('AVG(duration_ms) as avg_ms')
            ->groupBy('route_name')
            ->get()
            ->each(function ($row) {
                $p95 = $this->percentile($row->route_name, 95);
                $tier = $this->classify($p95);

                DB::table('sentinel_summaries')->updateOrInsert(
                    ['route_name' => $row->route_name],
                    ['p95_ms' => $p95, 'avg_ms' => $row->avg_ms, 'sample_count' => $row->sample_count,
                      'tier' => $tier, 'last_computed_at' => now()]
                );
            });
    }
}
```

**Use p95, not average, for tiering** — this was the whole point of doing this properly instead of eyeballing Telescope logs. Averages hide the slow tail that users actually feel.

---

## 6. Phase 3 — CLI Report (3–4 days) — **build this first, it's your MVP**

```
php artisan sentinel:report

┌─────────────────────────────┬────────┬────────┬────────┬─────────┬──────────────┐
│ Route                       │ p95    │ Avg    │ Samples│ Tier    │ N+1?         │
├─────────────────────────────┼────────┼────────┼────────┼─────────┼──────────────┤
│ GET  api/orders              │ 1420ms │ 890ms  │ 340    │ CRITICAL│ Yes (x14)    │
│ GET  api/orders/{id}         │ 210ms  │ 140ms  │ 1200   │ ACCEPT. │ No           │
│ POST api/orders               │ 95ms   │ 60ms   │ 800    │ GOOD    │ No           │
└─────────────────────────────┴────────┴────────┴────────┴─────────┴──────────────┘

php artisan sentinel:report --tier=critical --route=api/orders   # drill in, shows top offending queries + caller file:line
```

Ship this, dogfood it on one of your own real projects, and see if the categorization and N+1 detection actually hold up before building anything else. This is your validation checkpoint — if the CLI output isn't genuinely useful to you day-to-day, the IDE extension won't save it.

**Prioritize FamilyTree Pro as the stress test, not just "a" test subject.** A simple `users`/`posts` dummy schema won't exercise the fingerprinting or lazy-loading detection hard enough to trust it — self-referential, recursive relationship structures (ancestor trees are exactly this) are where N+1 edge cases actually hide. If the detection logic survives that schema cleanly, it'll hold up almost anywhere else. Zametrix and COP are still worth a pass afterward for broader route-shape coverage, but don't treat them as the primary bar.

---

## 7. Phase 4 — Local Read API (3–4 days)

Thin route group, gated so it never accidentally ships live in production:

```php
Route::middleware(['local-or-debug-only'])->prefix('_sentinel/api')->group(function () {
    Route::get('summaries', [SentinelController::class, 'summaries']);
    Route::get('summaries/{route}/queries', [SentinelController::class, 'topQueries']);
});
```

This is the entire contract between your PHP package and any client (CLI, extension, future web dashboard). Keep it boring JSON, no auth complexity for v1 (it's local-only), and version it (`/_sentinel/api/v1/...`) from day one so you can change the shape later without breaking installed extensions.

---

## 8. Phase 5 — VS Code Extension (3–5 weeks — the real effort)

This is where you go from "another Telescope clone" to the actual differentiator: **click a slow endpoint, land on the exact line of code causing it.**

**Architecture:**
- Extension activates on workspace open if it detects a `sentinel` config file (or on command palette trigger)
- Extension makes HTTP calls to the app's local `_sentinel/api` endpoint (user configures base URL once, e.g. `http://localhost:8000`)
- Results rendered in a **Webview panel** (HTML/CSS/JS sandboxed inside VS Code, same tier table as the CLI but clickable)
- Clicking a route drills into its top offending queries
- Clicking a query with a captured `caller_file`/`caller_line` calls `vscode.window.showTextDocument()` + reveals that exact line — **this is the feature Telescope/Pulse/Nightwatch's web dashboards structurally cannot give you**, because it means the tool understands your local filesystem, not just an abstract request log

**Skeleton:**

```typescript
// extension.ts
import * as vscode from 'vscode';

export function activate(context: vscode.ExtensionContext) {
  context.subscriptions.push(
    vscode.commands.registerCommand('sentinel.showDashboard', () => {
      const panel = vscode.window.createWebviewPanel(
        'sentinel', 'Sentinel', vscode.ViewColumn.One, { enableScripts: true }
      );
      panel.webview.html = getWebviewHtml();
      panel.webview.onDidReceiveMessage(async (msg) => {
        if (msg.command === 'openFile') {
          const doc = await vscode.workspace.openTextDocument(msg.file);
          const editor = await vscode.window.showTextDocument(doc);
          const pos = new vscode.Position(msg.line - 1, 0);
          editor.selection = new vscode.Selection(pos, pos);
          editor.revealRange(new vscode.Range(pos, pos));
        }
      });
      fetchSummaries().then(data => panel.webview.postMessage({ type: 'data', data }));
    })
  );
}
```

**Don't build:** real-time push/websockets, multi-project switching, or theming customization for v1. Poll-on-open + manual refresh button is enough to prove the concept.

**Vanilla JS first, React/Tailwind/Vite only if the interactions demand it.** A React+Tailwind webview bundled via Vite would make the expand-route → see-queries drill-down easier to maintain long-term, but it adds a real bundling/CSP setup cost on top of the extension scaffolding itself — cost the plan hasn't budgeted for. Don't take that on until Phase 5 actually starts and you've confirmed plain JS can't handle the interaction cleanly; at v1's scope (one table, expand/collapse, click-to-jump) it almost certainly can.

**Skip glassmorphism, and be deliberate about the rest of the aesthetic.** A dark, minimal table is a fine goal, but glassmorphism/gradient panels read as exactly the generic-AI-tool look you're trying to avoid across your UI work generally — treat this webview the same as any other frontend surface: an intentional design pass, not a default template.

---

## 9. Phase 6 — PhpStorm Plugin (later, only if v1 proves demand)

Separate SDK (Java/Kotlin), separate release cadence, roughly doubles ongoing maintenance. Blackfire already has a PhpStorm plugin covering similar ground. Don't start this until the VS Code version has real installs and feedback — building it speculatively is the single easiest way to burn a month on something nobody asked for.

---

## 10. Testing Strategy

- **Fingerprinting/N+1 detection:** this is the part most likely to have subtle bugs — write a dedicated test suite with real captured SQL samples (varying bindings, IN-lists, JOINs) and assert correct grouping. False positives here will destroy trust in the tool fast.
- **Collector overhead:** benchmark `DB::listen` overhead with and without the package on a sample app — publish this number in your README. If you can't say "adds <2ms overhead per request," people won't run it anywhere but local.
- **Package integration tests:** use Orchestra Testbench (standard for Laravel package testing) with a dummy app + SQLite to exercise the full listen → store → aggregate → report pipeline.
- **Extension:** manual test matrix against a real dummy Laravel app first; VS Code extension testing tooling (`@vscode/test-electron`) once the UI stabilizes — don't invest in extension test automation before the UI is settled, it'll just get rewritten.

---

## 11. Packaging & Distribution

- Standard Composer package structure, `ServiceProvider` with `publishes()` for config/migrations
- Semver from day one; `1.0.0` is the CLI + collector MVP, not "everything"
- Publish to Packagist under your vendor namespace
- README needs, non-negotiably: the overhead benchmark number, a GIF of the CLI report, a GIF of the VS Code jump-to-code feature — these three things sell the tool faster than any prose description

---

## 12. Monetization — Open Core, not SaaS

Given Nightwatch is first-party and Blackfire is established, positioning matters more than features:

- **Free/open-source:** collector, CLI report, single-project VS Code extension — this is your Fiverr/portfolio proof of senior Laravel work regardless of whether it ever makes money directly
- **Pro (license key, one-time or annual, not hosted SaaS):** historical trend charts, multi-project support in the extension, Slack/email alerts on tier regression, CSV/PDF export for stakeholder reports
- Avoid hosting anyone else's data — that's the expensive, support-heavy part of SaaS and it's not where your differentiation is anyway

---

## 13. Launch Checklist (once Phase 3 CLI is dogfooded and solid)

**Stay private through the FamilyTree Pro dogfooding checkpoint before any public release, beta included.** Given N+1 false-positive rate is the single biggest trust-risk in this whole plan, an early public beta that misfires on real users' schemas is a worse first impression than launching a few weeks later with detection that's actually held up against a gnarly schema.

1. Post the CLI MVP (not the extension yet) to r/laravel and Laravel News submission form — get real feedback before building the extension
2. Only build Phase 5 (VS Code extension) if that feedback validates the categorization/N+1 approach
3. dev.to/Medium write-up: "Why I built this instead of using Telescope" — technical honesty about trade-offs sells better than marketing copy
4. Link it from your portfolio and Fiverr profile as a showcased OSS project — this is worth more to your Fiverr credibility than almost any client work you could screenshot

---

## 14. Realistic Timeline (solo, part-time alongside client work)

| Phase | Duration |
|---|---|
| 0 — Scope lock | 1 day |
| 1 — Collector core | 1.5–2 weeks |
| 2 — Aggregation | 1 week |
| 3 — CLI report (MVP checkpoint) | 3–4 days |
| **→ Dogfood + decide whether to continue** | |
| 4 — Local read API | 3–4 days |
| 5 — VS Code extension | 3–5 weeks |
| 6 — PhpStorm plugin | not scheduled — v2 decision only |

**Total to a genuinely shippable v1 (through Phase 5): ~9–12 weeks part-time.** The CLI-only MVP (Phases 0–3) is reachable in **3–4 weeks** and is the point where you'll actually know if this is worth continuing — treat it as a hard checkpoint, not a formality.

---

## Honest risks

- N+1 detection false-positive rate is still the thing most likely to erode trust, even with two signals instead of one — the lazy-loading hook is precise but only covers Eloquent relations, its auto-chaining only protects against handlers registered *before* your package boots (not after), and the repeat-count heuristic covering raw query-builder N+1s still needs real tuning time against messy real-world data before you call it done
- `debug_backtrace()` caller capture is genuinely expensive even with both flags set correctly; if you get this wrong, "add <2ms overhead" becomes a lie and kills adoption
- The VS Code extension is where solo-dev time actually goes off a cliff — webview + extension host communication has a learning curve if you haven't built one before; budget the higher end of that 3–5 week estimate, and resist bolting on a React/Vite build pipeline before you've confirmed you actually need it
- Competing narrative against Nightwatch will keep coming up — your answer needs to be "free, local-first, IDE-native," not "does more than Nightwatch," because it won't