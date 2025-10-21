<?php

declare(strict_types=1);

if (!function_exists('array_key_first')) {
    /**
     * Polyfill for array_key_first for PHP versions < 7.3.
     *
     * @param array<mixed, mixed> $array
     * @return int|string|null
     */
    function array_key_first(array $array)
    {
        foreach ($array as $key => $value) {
            return $key;
        }

        return null;
    }
}
