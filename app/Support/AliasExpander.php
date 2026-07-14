<?php

namespace App\Support;

/**
 * Expands user-defined command aliases in argv before Symfony Console parses
 * it, so "bb co 42" (alias co = pr:checkout) becomes "bb pr:checkout 42".
 */
class AliasExpander
{
    /**
     * @param  array<int, string>  $argv
     * @param  array<string, string>  $aliases
     * @return array<int, string>
     */
    public static function expand(array $argv, array $aliases): array
    {
        if ($aliases === []) {
            return $argv;
        }

        // Find the command token — the first argument that isn't an option.
        for ($i = 1; $i < count($argv); $i++) {
            if (! str_starts_with($argv[$i], '-')) {
                if (isset($aliases[$argv[$i]])) {
                    $expansion = array_values(array_filter(explode(' ', (string) $aliases[$argv[$i]]), 'strlen'));
                    array_splice($argv, $i, 1, $expansion);
                }

                return $argv;
            }
        }

        return $argv;
    }
}
