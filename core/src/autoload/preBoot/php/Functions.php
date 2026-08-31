<?php

function getRelativePath(string $absolutePath): string {
    // Globale Konstante ABS_BASE_PATH verwenden
    $basePath = rtrim(ABS_PUBLIC_PATH, '/') . '/';
    $absPath = rtrim($absolutePath, '/') . '/';

    if (strpos($absPath, $basePath) === 0) {
        $relative = substr($absolutePath, strlen($basePath));
        return '/' . ltrim($relative, '/');
    }

    return $absolutePath; // fallback, falls basePath nicht gefunden wird
}


