<?php

namespace AsimAli\Pinpoint;

use AsimAli\Pinpoint\Commands\AggregateCommand;
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
            ->hasCommand(ReportCommand::class)
            ->hasCommand(PruneCommand::class)
            ->hasMigration('2026_01_01_000001_create_pinpoint_requests_table')
            ->hasMigration('2026_01_01_000002_create_pinpoint_queries_table')
            ->hasMigration('2026_01_01_000003_create_pinpoint_summaries_table');
    }

    public function registeringPackage(): void
    {
        $this->app->singleton(Recorder::class, fn () => new Recorder($this->app->make('config')));

        $this->app->singleton(Pinpoint::class, fn () => new Pinpoint($this->app->make(Recorder::class)));
    }

    public function bootingPackage(): void
    {
        $this->registerQueryListener();
        $this->registerRequestListener();
        $this->registerLazyLoadingObserver();
    }

    protected function registerQueryListener(): void
    {
        $recorder = $this->app->make(Recorder::class);

        if (! $recorder->isRecording()) {
            return;
        }

        $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $query) use ($recorder) {
            try {
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
        $recorder = $this->app->make(Recorder::class);

        if (! $recorder->isRecording()) {
            return;
        }

        $this->app['events']->listen(RequestHandled::class, function (RequestHandled $event) use ($recorder) {
            try {
                $request = $event->request;

                if (! $recorder->shouldRecord($request)) {
                    $recorder->reset();

                    return;
                }

                $recorder->flush([
                    'route_name' => $request->route()?->getName(),
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'duration_ms' => (microtime(true) - $this->requestStart()) * 1000,
                ]);
            } catch (\Throwable $e) {
                // Fail silently: the host app must never break because Pinpoint
                // couldn't write its own tables (e.g. migrations not run yet).
                if (str_contains($e->getMessage(), 'no such table')) {
                    Log::warning('Pinpoint: tables missing — run `php artisan vendor:publish --tag=pinpoint-migrations` then `php artisan migrate`.');
                } else {
                    Log::warning('Pinpoint: failed to flush request', ['exception' => $e->getMessage()]);
                }

                $recorder->reset();
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
        if (! config('pinpoint.capture_lazy_loading_violations', true)) {
            return;
        }

        $recorder = $this->app->make(Recorder::class);
        $existing = $this->currentLazyLoadingCallback();

        Model::preventLazyLoading(! app()->isProduction());
        Model::handleLazyLoadingViolationUsing(function ($model, $relation) use ($existing, $recorder) {
            try {
                $recorder->recordLazyLoad(get_class($model), $relation);
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
