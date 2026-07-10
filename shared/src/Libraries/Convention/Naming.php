<?php

namespace Z77\Shared\Libraries\Convention;

use Z77\Shared\Libraries\Cleaner\StringCleaner;

final class Naming
{
    private function __construct() {}

    /**
     * Converts a string to CamelCase (PascalCase).
     * @param string $input
     * @param bool $onlyAscii Remove non-ASCII characters (e.g. umlauts)
     * @return string
     */
    public static function toCamelCase(string $input, bool $onlyAscii = true): string
    {
        if ($onlyAscii) {
            $input = self::asciiTransliterate($input);
        }

        // Replace separators with spaces
        $input = preg_replace('/[^a-zA-Z0-9]+/', ' ', $input);

        // Already camelCase/PascalCase without separators -> only uppercase the first character
        // (prevents 'passwordHash' from being destroyed to 'Passwordhash')
        if (strpos($input, ' ') === false) {
            return ucfirst($input);
        }

        // snake_case / kebab-case / normal text
        return str_replace(' ', '', ucwords(strtolower(trim($input))));
    }

    public static function toControllerClassName(string $prefix, string $group, string $controller): string
    {
        $segments = ['Ui', 'Controllers'];
        if ($group !== '') {
            $segments[] = $group;
        }

        return
            $prefix.
            self::toNamespaceString($segments).
            self::toCamelCase($controller).'Controller'
        ;
    }

    public static function toClassBaseName(object|string $class): string
    {
        // If an object is passed, resolve its class name
        $className = is_object($class) ? get_class($class) : $class;

        // Replace namespace separator (\) with / so basename() can be used
        return basename(str_replace('\\', '/', $className));
    }

    /**
     * Extracts the group segment from a controller FQCN.
     * `...\Ui\Controllers\Content\NavigationController` → `Content`.
     * Returns '' for flat (group-less) controllers `...\Ui\Controllers\IndexController`.
     *
     * The group is the namespace segment between `\Controllers\` and the class
     * base name. It is the single source of truth that lets view templates and
     * controller configs mirror the controller's physical location (ADR-005).
     */
    public static function toControllerGroupSegment(object|string $class): string
    {
        $fqcn   = is_object($class) ? get_class($class) : $class;
        $marker = '\\Controllers\\';
        $pos    = strrpos($fqcn, $marker);
        if ($pos === false) {
            return '';
        }

        $tail      = substr($fqcn, $pos + strlen($marker)); // e.g. "Content\NavigationController"
        $lastSlash = strrpos($tail, '\\');

        return $lastSlash !== false ? substr($tail, 0, $lastSlash) : '';
    }


    public static function toActionMethod(string $action): string
    {
        return self::toLcFirstCamelCase($action).'Action';
    }

    public static function toControllerUrlSegment(object|string $class): string
    {
        $base = preg_replace('/Controller$/i', '', self::toClassBaseName($class));
        // Multi-word PascalCase → kebab-case so the segment round-trips back to
        // the class via toCamelCase (e.g. NavigationGroup → navigation-group).
        // Single-word names are unaffected (no interior uppercase to split).
        $base = preg_replace('/(?<!^)[A-Z]/', '-$0', $base);
        return strtolower($base);
    }

    public static function toActionUrlSegment(string $actionMethod): string
    {
        return strtolower(preg_replace('/Action$/i', '', $actionMethod));
    }

    public static function toNamespaceString(array|string $parts): string
    {
        $parts = (array)$parts;
        $allParts = [];


        foreach ($parts as $part) {
            // Skip empty entries
            if (empty($part)) {
                continue;
            }

            // If the entry contains a backslash, split it into its segments
            if (str_contains($part, '\\')) {
                // Split the namespace segment and drop empties (from doubled backslashes)
                $subParts = array_filter(explode('\\', $part));
                $allParts = array_merge($allParts, $subParts);
            } else {
                // Otherwise append the entry as-is
                $allParts[] = $part;
            }
        }

        $allParts = array_map([self::class, 'toCamelCase'], $allParts);
        $namespaceString = implode('\\', $allParts);

        $namespaceString .= '\\';

        return $namespaceString;
    }

    public static function toGetter(string $input): string
    {
        return 'get'.self::toCamelCase($input);
    }

    public static function toSetter(string $input): string
    {
        return 'set'.self::toCamelCase($input);
    }

    /**
     * Converts a string to camelCase (first character lowercase).
     */
    public static function toLcFirstCamelCase(string $input, bool $onlyAscii = true): string
    {
        return lcfirst(self::toCamelCase($input, $onlyAscii));
    }

    /**
     * Converts a string to snake_case.
     */
    public static function toSnakeCase(string $input, bool $onlyAscii = true): string
    {
        if ($onlyAscii) {
            $input = self::asciiTransliterate($input);
        }

        $input = preg_replace('/(?<!^)[A-Z]/', '_$0', $input);
        $input = preg_replace('/[^a-zA-Z0-9]+/', '_', $input);
        $input = strtolower(trim($input, '_'));

        return $input;
    }

    /**
     * URL-safe single-segment slug: snake_case (umlaut transliteration + lowercasing)
     * with `_` separators swapped for `-`. The shared slug transform for DMS structural
     * URLs (folder + document path segments, ADR-017 §8). Callers drop any file extension
     * first; an empty / punctuation-only input yields an empty string.
     */
    public static function toSlug(string $input): string
    {
        return str_replace('_', '-', self::toSnakeCase($input));
    }

    /**
     * ASCII transliteration (e.g. ä -> ae)
     */
    private static function asciiTransliterate(string $input): string
    {
        $trans = [
            'ä'=>'ae', 'ö'=>'oe', 'ü'=>'ue',
            'Ä'=>'Ae', 'Ö'=>'Oe', 'Ü'=>'Ue',
            'ß'=>'ss',
        ];
        return strtr($input, $trans);
    }
}
