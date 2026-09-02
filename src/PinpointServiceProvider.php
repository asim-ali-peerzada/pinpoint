<?php

namespace AsimAli\Pinpoint;

use AsimAli\Pinpoint\Commands\AggregateCommand;
use AsimAli\Pinpoint\Commands\CheckCommand;
use AsimAli\Pinpoint\Commands\PruneCommand;
use AsimAli\Pinpoint\Commands\ReportCommand;
use AsimAli\Pinpoint\Commands\ResetCommand;
use AsimAli\Pinpoint\Internal\Recorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Log;
use ReflectionProperty;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class PinpointServiceProvider extends PackageServiceProvider
{
    protected float $startedAt;

    public function configurePackage(Package $package): void
    {
        $this->startedAt = microtime(true);

        $package
            ->name('pinpoint')
            ->hasConfigFile()
            ->hasRoute('api')
            ->hasCommand(AggregateCommand::class)
            ->hasCommand(CheckCommand::class)
            ->hasCommand(ReportCommand::class)
            ->hasCommand(PruneCommand::class)
            ->hasCommand(ResetCommand::class)
            ->hasMigration('create_pinpoint_requests_table')
            ->hasMigration('create_pinpoint_queries_table')
            ->hasMigration('create_pinpoint_summaries_table')
            ->hasMigration('create_pinpoint_lazy_loads_table');
    }

    public function registeringPackage(): void
    {
        // scoped() (not singleton) so per-request mutable state is cleared
        // between requests under Octane / long-running workers.
        $this->app->scoped(Recorder::class, fn() => new Recorder($this->app->make('config')));

        $this->app->scoped(Pinpoint::class, fn() => new Pinpoint($this->app->make(Recorder::class)));
    }

    public function bootingPackage(): void
    {
        $this->registerQueryListener();
        $this->registerRequestListener();
        $this->registerLazyLoadingObserver();
    }

    protected function registerQueryListener(): void
    {
        if (! $this->app->make(Recorder::class)->isRecording()) {
            return;
        }

        $this->app->make('events')->listen(QueryExecuted::class, function (QueryExecuted $query) {
            try {
                // Resolve per event, never capture: under Octane/queue workers
                // the container clears scoped instances between jobs/requests,
                // and a captured reference would keep writing to a stale
                // instance forever (unbounded memory growth).
                $recorder = $this->app->make(Recorder::class);

                $recorder->recordQuery([
                    'sql' => $query->sql,
                    'fingerprint' => QueryFingerprinter::hash($query->sql),
                    'bindings_hash' => self::hashBindings($query->bindings),
                    'time_ms' => $query->time,
                    'caller' => $recorder->capturesCaller() ? Caller::capture(base_path()) : null,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Pinpoint: failed to record query', ['exception' => $e->getMessage()]);
            }
        });
    }

    protected function registerRequestListener(): void
    {
        if (! $this->app->make(Recorder::class)->isRecording()) {
            return;
        }

        $this->app->make('events')->listen(RequestHandled::class, function (RequestHandled $event) {
            try {
                // Same per-event resolution as the query listener (see above).
                $recorder = $this->app->make(Recorder::class);
                $request = $event->request;

                if (! $recorder->shouldRecord()) {
                    $recorder->reset();

                    return;
                }

                // Capture the request metadata now, defer the DB writes until
                // the response is sent (app()->terminating runs after send()).
                // Keeps the flush off the user-facing response path.
                $payload = [
                    'route_name' => $request->route()?->getName(),
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'duration_ms' => (microtime(true) - $this->requestStart()) * 1000,
                    // Snapshot peak RSS *before* terminating callbacks run so
                    // Pinpoint's own DB writes don't inflate the measurement.
                    // real_usage=true matches Xdebug / Blackfire convention and
                    // reflects OS-level pages allocated, not emalloc blocks.
                    'peak_memory_kb' => (int) round(memory_get_peak_usage(true) / 1024),
                ];

                $this->app->terminating(function () use ($recorder, $payload) {
                    try {
                        $recorder->flush($payload);
                    } catch (\Throwable $e) {
                        // Fail silently: the host app must never break because
                        // Pinpoint couldn't write its own tables (e.g.
                        // migrations not run yet).
                        if (str_contains($e->getMessage(), 'no such table')) {
                            Log::warning('Pinpoint: tables missing — run `php artisan vendor:publish --tag=pinpoint-migrations` then `php artisan migrate`.');
                        } else {
                            Log::warning('Pinpoint: failed to flush request', ['exception' => $e->getMessage()]);
                        }
                    }
                });
            } catch (\Throwable $e) {
                Log::warning('Pinpoint: failed to prepare flush', ['exception' => $e->getMessage()]);
            }
        });
    }

    /**
     * Auto-chain: captures whatever handler already exists at boot time via
     * Reflection (there's no public getter for this property) and calls it
     * after recording. Reflection can't fix the reverse direction (a handler
     * registered in a provider booting *after* ours overwrites us) — apps in
     * that situation should call Pinpoint::observeLazyLoad() in their handler.
     */
    protected function registerLazyLoadingObserver(): void
    {
        // The master switch must fully disable the package: no Eloquent
        // global state changes, no handler chaining, when disabled.
        if (! $this->app->make(Recorder::class)->isRecording() || ! config('pinpoint.capture_lazy_loading_violations', true)) {
            return;
        }

        $existing = $this->currentLazyLoadingCallback();

        // preventLazyLoading() only needs to be flipped on outside production
        // (that's where Pinpoint records instead of throwing); in production
        // leave the host app's own strict-mode choice untouched.
        if (! app()->isProduction() && ! Model::preventsLazyLoading()) {
            Model::preventLazyLoading(true);
        }

        Model::handleLazyLoadingViolationUsing(function ($model, $relation) use ($existing) {
            try {
                // Resolve per call (never capture) — same scoped-instance
                // rationale as the query listener, for Octane/queue workers.
                $recorder = $this->app->make(Recorder::class);

                $recorder->recordLazyLoad(
                    get_class($model),
                    $relation,
                    $recorder->capturesCaller() ? Caller::capture(base_path()) : null
                );
            } catch (\Throwable $e) {
                Log::warning('Pinpoint: failed to record lazy load', ['exception' => $e->getMessage()]);
            }

            if ($existing) {
                $existing($model, $relation);
            }
        });
    }

    protected function requestStart(): float
    {
        // REQUEST_TIME_FLOAT is set per request by PHP-FPM and Octane —
        // prefer it over LARAVEL_START, which is worker-boot time under
        // Octane (a request would otherwise appear millions of ms long).
        if (isset($_SERVER['REQUEST_TIME_FLOAT'])) {
            return (float) $_SERVER['REQUEST_TIME_FLOAT'];
        }

        return defined('LARAVEL_START') ? LARAVEL_START : $this->startedAt;
    }

    /**
     * Hash the bound values for duplicate-query detection.
     *
     * Normalisation rules:
     *   - Non-array bindings (some drivers pass a scalar) are wrapped first.
     *   - Values are cast to string: integer 1 and string "1" bind identically
     *     in SQL and must produce the same hash.
     *   - Null is kept as null (a NULL binding IS semantically distinct).
     *   - The array is re-indexed before encoding so [0 => 'a'] and
     *     [1 => 'a'] (possible after array_values vs list spread) hash the same.
     *   - Returns null when the bindings list is empty — the fingerprint alone
     *     already identifies the query shape; no extra signal is needed.
     */
    protected static function hashBindings(mixed $bindings): ?string
    {
        if (! is_array($bindings)) {
            $bindings = [$bindings];
        }

        if ($bindings === []) {
            return null;
        }

        $normalised = array_map(
            fn($v) => $v === null ? null : (string) $v,
            array_values($bindings)
        );

        return hash('xxh128', json_encode($normalised, JSON_THROW_ON_ERROR));
    }

    protected function currentLazyLoadingCallback(): ?callable
    {
        try {
            $prop = new ReflectionProperty(Model::class, 'lazyLoadingViolationCallback');

            return $prop->getValue(); // NOSONAR
        } catch (\Throwable) {
            return null;
        }
    }
}
