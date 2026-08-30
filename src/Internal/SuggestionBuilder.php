<?php

namespace AsimAli\Pinpoint\Internal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

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

            // Index-based mutation, not foreach-by-reference: the & alias +
            // unset dance is the classic PHP reference trap (remove the
            // unset in a future refactor and the last element silently
            // aliases the loop variable).
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
