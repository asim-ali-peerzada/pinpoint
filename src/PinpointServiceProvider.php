<?php

namespace AsimAli\Pinpoint;

use AsimAli\Pinpoint\Commands\AggregateCommand;
use AsimAli\Pinpoint\Commands\CheckCommand;
use AsimAli\Pinpoint\Commands\PruneCommand;
use AsimAli\Pinpoint\Commands\ReportCommand;
use AsimAli\Pinpoint\Internal\Recorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use ReflectionException;
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
            ->hasMigration('2026_01_01_000001_create_pinpoint_requests_table')
            ->hasMigration('2026_01_01_000002_create_pinpoint_queries_table')
            ->hasMigration('2026_01_01_000003_create_pinpoint_summaries_table');
    }

    public function registeringPackage(): void
    {
        // scoped() (not singleton) so per-request mutable state is cleared
        // between requests under Octane / long-running workers.
        $this->app->scoped(Recorder::class, fn () => new Recorder($this->app->make('config')));

        $this->app->scoped(Pinpoint::class, fn () => new Pinpoint($this->app->make(Recorder::class)));
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

        $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $query) {
            try {
                // Resolve per event, never capture: under Octane/queue workers
                // the container clears scoped instances between jobs/requests,
                // and a captured reference would keep writing to a stale
                // instance forever (unbounded memory growth).
                $recorder = $this->app->make(Recorder::class);

                $recorder->recordQuery([
                    'sql' => $query->sql,
                    'fingerprint' => QueryFingerprinter::hash($query->sql),
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

        $this->app['events']->listen(RequestHandled::class, function (RequestHandled $event) {
            try {
                // Same per-event resolution as the query listener (see above).
                $recorder = $this->app->make(Recorder::class);
                $request = $event->request;

                if (! $recorder->shouldRecord($request)) {
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
                $this->app->make(Recorder::class)->recordLazyLoad(get_class($model), $relation);
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

    protected function currentLazyLoadingCallback(): ?callable
    {
        try {
            $prop = (new ReflectionClass(Model::class))->getProperty('lazyLoadingViolationCallback');
            $prop->setAccessible(true);

            return $prop->getValue();
        } catch (ReflectionException) {
            return null;
        }
    }
}
