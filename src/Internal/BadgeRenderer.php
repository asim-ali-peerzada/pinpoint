<?php

namespace AsimAli\Pinpoint\Internal;

use AsimAli\Pinpoint\TierClassifier;

/**
 * @internal Encapsulates HTML badge and pill rendering for the CLI.
 */
class BadgeRenderer
{
    public static function header(string $title): string
    {
        return '<div class="mx-2 my-1">'
            .'<div class="flex justify-between w-full mb-1">'
            .'<span class="px-2 bg-blue-500 text-white font-bold uppercase">Pinpoint</span>'
            .'<span class="text-gray-400">'.e($title).'</span>'
            .'</div>';
    }

    /**
     * @param  array{tier: string, health?: string|null}  $row
     */
    public static function healthOrTier(array $row, bool $composite): string
    {
        if ($composite) {
            return self::health($row);
        }

        return self::tier($row['tier']);
    }

    /**
     * @param  array{tier: string, n1?: string, memory_over_budget?: bool, health?: string|null}  $row
     */
    public static function health(array $row): string
    {
        $tierLabel = strtoupper($row['tier']);

        $isHealthyTier = in_array($row['tier'], [TierClassifier::GOOD, TierClassifier::ACCEPTABLE], true);
        $hasN1 = isset($row['n1']) && str_starts_with($row['n1'], 'Yes');
        $overMemory = (bool) ($row['memory_over_budget'] ?? false);

        if ($isHealthyTier && ! $hasN1 && ! $overMemory) {
            return '<span class="px-1 bg-green-600 text-white font-bold">HEALTHY</span>';
        }

        return '<span><span class="px-1 bg-red-600 text-white font-bold">NEEDS WORK</span>'
            .'<span class="text-gray-600"> ('.$tierLabel.')</span></span>';
    }

    public static function tier(string $tier): string
    {
        $label = strtoupper($tier);

        return match ($tier) {
            TierClassifier::GOOD => '<span class="px-1 bg-green-600 text-white font-bold">'.$label.'</span>',
            TierClassifier::ACCEPTABLE => '<span class="px-1 bg-yellow-600 text-black font-bold">'.$label.'</span>',
            TierClassifier::NEEDS_IMPROVEMENT => '<span class="px-1 bg-orange-600 text-white font-bold">'.$label.'</span>',
            TierClassifier::CRITICAL => '<span class="px-1 bg-red-600 text-white font-bold">'.$label.'</span>',
            default => '<span class="text-gray-400">'.$label.'</span>',
        };
    }

    public static function n1(string $n1): string
    {
        if (str_starts_with($n1, 'Yes')) {
            return '<span class="text-red-500 font-bold">'.$n1.'</span>';
        }

        return '<span class="text-gray-600">No</span>';
    }

    public static function queryType(?string $type, int $count): string
    {
        return match ($type) {
            'duplicate' => '<span class="px-1 bg-cyan-600 text-white font-bold">CACHE x'.$count.'</span>',
            'n_plus_one' => '<span class="px-1 bg-red-600 text-white font-bold">N+1 x'.$count.'</span>',
            default => '<span class="px-1 bg-yellow-600 text-black font-bold">REPEAT x'.$count.'</span>',
        };
    }

    public static function memoryCell(?string $formatted, bool $overBudget): string
    {
        if ($formatted === null) {
            return '<span class="text-gray-600">—</span>';
        }

        return $overBudget
            ? '<span class="text-red-500 font-bold">'.$formatted.'</span>'
            : '<span class="text-gray-300">'.$formatted.'</span>';
    }
}
