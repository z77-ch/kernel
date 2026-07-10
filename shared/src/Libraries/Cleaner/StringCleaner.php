<?php

namespace Z77\Shared\Libraries\Cleaner;

final class StringCleaner
{
    private const ASCII_REPLACEMENTS = [
        'Ä' => 'Ae', 'À' => 'A', 'Â' => 'A', 'Æ' => 'A', 'Ç' => 'C',
        'Ü' => 'Ue', 'Ù' => 'U', 'Û' => 'U', 'Ö' => 'Oe', 'Ô' => 'O', 'Œ' => 'O',
        'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Î' => 'I', 'Ï' => 'I',
        'ä' => 'ae', 'ü' => 'ue', 'ö' => 'oe', 'ô' => 'o', 'œ' => 'o',
        'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'à' => 'a', 'â' => 'a', 'æ' => 'a', 'î' => 'i', 'ï' => 'i',
        'ù' => 'u', 'û' => 'u', 'Ÿ' => 'Y', 'ÿ' => 'y', ' ' => '_',
    ];

    /**
     * Entfernt alle Zeichen außer a-z und -
     */
    public static function cleanAlpha(string $input, string $replacement = ''): string
    {
        $input = strtolower($input);
        return preg_replace('/[^a-z\-_\/]/', $replacement, $input);
    }

    /**
     * Entfernt alle Zeichen außer 0-9, a-z und -
     */
    public static function cleanAlphaNum(string $input, string $replacement = ''): string
    {
        $input = strtolower($input);
        return preg_replace('/[^0-9a-z\-_\/]/', $replacement, $input);
    }

    /**
     * Ersetzt Umlaute und entfernt alle nicht-ASCII Zeichen.
     */
    public static function cleanAscii(string $input, bool $lower = true, string $pattern = '/[^0-9a-zA-Z_\.\-\/]/', string $replacement = ''): string
    {
        return self::cleanWithReplacements($input, self::ASCII_REPLACEMENTS, $pattern, $replacement, $lower);
    }

    /**
     * Für Dateinamen (erlaubt Punkte und Minus)
     */
    public static function cleanFileName(string $input): string
    {
        return self::cleanAscii($input, false, '/[^0-9a-zA-Z_\.\-]/');
    }

    /**
     * Für Verzeichnisnamen (erlaubt Schrägstriche)
     */
    public static function cleanDirName(string $input): string
    {
        return self::cleanAscii($input, false, '/[^0-9a-zA-Z_\-\/]/');
    }

    /**
     * Kernfunktion für alle Cleaner
     */
    private static function cleanWithReplacements(
        string $input,
        array $replacements,
        string $pattern,
        string $replacement,
        bool $lower
    ): string {
        if ($lower) {
            $input = mb_strtolower($input);
        }

        $input = strtr($input, $replacements);

        $input = preg_replace([
            $pattern,
            '/\.+/',   // mehrere Punkte
            '/_+/',    // mehrere Unterstriche
            '/-+/',    // mehrere Bindestriche
            '/\/+/',   // mehrere Slashes
        ], [
            $replacement,
            '.',
            '_',
            '-',
            '/',
        ], $input);

        return $input;
    }
}
