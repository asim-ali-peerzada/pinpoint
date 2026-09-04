<?php

namespace AsimAli\Pinpoint\Internal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * @internal Not part of Pinpoint's public API contract.
 */
class SuggestionBuilder
{
    /**
     * Build actionable eager-loading suggestions from persisted lazy-load
     * violations. Chains nested relations: if model A lazily loads `stages`
     * and the stage model lazily loads `photos`, the suggestion is
     * `A::with('stages.photos')`.
     *
     * @param  array<int, array{model: string, relation: string, caller_file: string|null, caller_line: int|null}>  $violations
     * @return array<int, array{model: string, relations: string, caller_file: string|null, caller_line: int|null}>
     */
    public function build(array $violations): array
    {
        $chains = [];

        foreach ($violations as $violation) {
            $model = $violation['model'];
            $relation = $violation['relation'];

            // Chain onto any previously built chain whose last link resolves
            // to this violation's model (e.g. stages -> photos). Skipped when
            // the model equals the chain's root (self-referential guard).
            $chained = false;

            // Mutate by index to avoid foreach-by-reference aliasing.
            foreach ($chains as $i => $chain) {
                if ($chain['model'] === $model || $this->relatedClass($chain['model'], $chain['relations']) !== $model) {
                    continue;
                }

                $chains[$i]['relations'] .= '.'.$relation;
                $chained = true;

                break;
            }

            if (! $chained) {
                $chains[] = [
                    'model' => $model,
                    'relations' => $relation,
                    'caller_file' => $violation['caller_file'],
                    'caller_line' => $violation['caller_line'],
                ];
            }
        }

        // Dedupe identical chains (e.g. the same relation violated on
        // multiple iterations of a loop within one request).
        $seen = [];
        $unique = [];

        foreach ($chains as $chain) {
            $key = $chain['model'].'|'.$chain['relations'];

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $chain;
            }
        }

        return $unique;
    }

    /**
     * Eager-loading suggestion chains for a route label, deduped across the
     * most recent requests. A chain must reflect a violation that actually
     * happened inside one request — merging across requests would fabricate
     * chains that never occurred.
     *
     * @return array<int, array{model: string, relations: string, caller_file: string|null, caller_line: int|null}>
     */
    public function forRoute(string $routeLabel, int $requestLimit = 20, ?int $sinceMinutes = null): array
    {
        // Bound the request set: the whereIn lookup below would otherwise
        // grow without bound on frequently recorded routes (SQLite bind
        // limit / MySQL packet limit / memory).
        $requestIdsQuery = QueryReader::scopeRouteLabel(
            DB::table('pinpoint_requests')->select('id'),
            $routeLabel
        )->orderByDesc('id')->limit($requestLimit);

        if ($sinceMinutes !== null) {
            $requestIdsQuery->where('created_at', '>=', now()->subMinutes($sinceMinutes));
        }

        $requestIds = $requestIdsQuery->pluck('id');

        if ($requestIds->isEmpty()) {
            return [];
        }

        $violations = DB::table('pinpoint_lazy_loads')
            ->whereIn('request_id', $requestIds)
            ->select('request_id', 'model', 'relation', 'caller_file', 'caller_line')
            ->get()
            ->map(fn ($row) => [
                'request_id' => $row->request_id,
                'model' => $row->model,
                'relation' => $row->relation,
                'caller_file' => $row->caller_file,
                'caller_line' => $row->caller_line,
            ])
            ->unique(fn ($row) => $row['request_id'].'|'.$row['model'].'->'.$row['relation'])
            ->groupBy('request_id');

        $chains = [];

        foreach ($violations as $rows) {
            foreach ($this->build($rows->values()->all()) as $chain) {
                $key = $chain['model'].'|'.$chain['relations'];

                if (! isset($chains[$key])) {
                    $chains[$key] = $chain;
                }
            }
        }

        return array_values($chains);
    }

    /**
     * Resolve the related model class for a relation chain via Reflection-free
     * Eloquent API. Returns null when the relation can't be resolved
     * (guarded: a broken model must never break the suggestion feature).
     */
    protected function relatedClass(string $model, string $relations): ?string
    {
        try {
            return $this->resolveRelatedModel($model, $relations)::class;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function resolveRelatedModel(string $model, string $relations): object
    {
        // The model/relation strings come from persisted rows — validate
        // before invoking anything so a non-relation method's side effects
        // can never run.
        if (! is_subclass_of($model, Model::class)) {
            throw new \InvalidArgumentException("Not an Eloquent model: {$model}");
        }

        $instance = new $model;

        foreach (explode('.', $relations) as $segment) {
            if (! method_exists($instance, $segment)) {
                throw new \InvalidArgumentException("Unknown relation: {$segment}");
            }

            $relation = $instance->{$segment}();

            if (! $relation instanceof Relation) {
                throw new \InvalidArgumentException("Not a relation: {$segment}");
            }

            $instance = $relation->getRelated();
        }

        return $instance;
    }
}
