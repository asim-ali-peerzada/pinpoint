<?php

namespace AsimAli\Pinpoint;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;
use ReflectionException;

class PinpointServiceProvider extends ServiceProvider
{
    protected float $startedAt;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/pinpoint.php', 'pinpoint');

        $this->startedAt = microtime(true);

        $this->app->singleton(Pinpoint::class, fn (Application $app) => new Pinpoint($app->make('config')));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/pinpoint.php' => config_path('pinpoint.php'),
        ], 'pinpoint-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'pinpoint-migrations');

        $this->registerQueryListener();
        $this->registerRequestListener();
        $this->registerLazyLoadingObserver();
    }

    protected function registerQueryListener(): void
    {
        if (! $this->app->make(Pinpoint::class)->isRecording()) {
            return;
        }

        $pinpoint = $this->app->make(Pinpoint::class);

        $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $query) use ($pinpoint) {
            if (! $pinpoint->isRecording()) {
                return;
            }

            $pinpoint->recordQuery([
                'sql' => $query->sql,
                'fingerprint' => QueryFingerprinter::hash($query->sql),
                'time_ms' => $query->time,
                'caller' => $pinpoint->capturesCaller() ? Caller::capture(base_path()) : null,
            ]);
        });
    }

    protected function registerRequestListener(): void
    {
        if (! $this->app->make(Pinpoint::class)->isRecording()) {
            return;
        }

        $pinpoint = $this->app->make(Pinpoint::class);

        $this->app['events']->listen(RequestHandled::class, function (RequestHandled $event) use ($pinpoint) {
            $request = $event->request;

            if (! $pinpoint->shouldRecord($request)) {
                $pinpoint->reset();

                return;
            }

            $pinpoint->flush([
                'route_name' => $request->route()?->getName(),
                'method' => $request->method(),
                'path' => $request->path(),
                'duration_ms' => (microtime(true) - $this->requestStart()) * 1000,
            ]);
        });
    }

    /**
     * Auto-chain: captures whatever handler already exists at boot time via
     * Reflection (there's no public getter for this property) and calls it
     * after recording. Reflection can't fix the reverse direction (a handler
     * registered in a provider booting *after* ours overwrites us) — the
     * README documents Pinpoint::recordLazyLoad() for that case.
     */
    protected function requestStart(): float
    {
        return defined('LARAVEL_START') ? LARAVEL_START : $this->startedAt;
    }

    protected function registerLazyLoadingObserver(): void
    {
        if (! config('pinpoint.capture_lazy_loading_violations', true)) {
            return;
        }

        $pinpoint = $this->app->make(Pinpoint::class);
        $existing = $this->currentLazyLoadingCallback();

        Model::preventLazyLoading(! app()->isProduction());
        Model::handleLazyLoadingViolationUsing(function ($model, $relation) use ($existing, $pinpoint) {
            $pinpoint->recordLazyLoad(get_class($model), $relation);

            if ($existing) {
                $existing($model, $relation);
            }
        });
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