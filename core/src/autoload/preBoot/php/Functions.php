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

function url_origin( $s, $use_forwarded_host = false )
{
    $serverProtocol = (isset($s['SERVER_PROTOCOL'])) ? $s['SERVER_PROTOCOL'] : '';

    if (!$serverProtocol) return URL_ORIGIN;

    $serverName = (isset($s['SERVER_NAME'])) ? $s['SERVER_NAME'] : '';
    $serverPort = (isset($s['SERVER_PORT'])) ? $s['SERVER_PORT'] : '';

    $ssl      = ( ! empty( $s['HTTPS'] ) && $s['HTTPS'] == 'on' );
    $sp       = strtolower( $serverProtocol );
    $protocol = substr( $sp, 0, strpos( $sp, '/' ) ) . ( ( $ssl ) ? 's' : '' );
    $port     = $serverPort;
    $port     = ( ( ! $ssl && $port=='80' ) || ( $ssl && $port=='443' ) ) ? '' : ':'.$port;
    $host     = ( $use_forwarded_host && isset( $s['HTTP_X_FORWARDED_HOST'] ) ) ? $s['HTTP_X_FORWARDED_HOST'] : ( isset( $s['HTTP_HOST'] ) ? $s['HTTP_HOST'] : null );
    $host     = isset( $host ) ? $host : $serverName . $port;

    return $protocol . '://' . $host;
}


