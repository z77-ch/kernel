<?php
/**
 * Gibt alle Properties eines Objekts rekursiv aus, inklusive private/protected
 *
 * @param object $obj
 * @param int $depth Rekursionstiefe, um Endlosschleifen zu vermeiden
 * @param int $maxDepth Maximale Rekursionstiefe
 * @return array
 */
function getAllPropertiesRecursive(object $obj, int $depth = 0, int $maxDepth = 5): array
{
    if ($depth > $maxDepth) {
        return ['__maxDepthReached' => true];
    }

    $result = [];
    $refClass = new \ReflectionClass($obj);

    foreach ($refClass->getProperties() as $property) {
        $property->setAccessible(true);
        $propName = $property->getName();

        // Prüfen, ob Property initialisiert ist
        if ($property->isInitialized($obj)) {
            $value = $property->getValue($obj);
        } else {
            $value = null;
        }

        if (is_object($value)) {
            $result[$propName] = getAllPropertiesRecursive($value, $depth + 1, $maxDepth);
        } elseif (is_array($value)) {
            $result[$propName] = array_map(function($item) use ($depth, $maxDepth) {
                if (is_object($item)) {
                    return getAllPropertiesRecursive($item, $depth + 1, $maxDepth);
                }
                return $item;
            }, $value);
        } else {
            $result[$propName] = $value;
        }
    }

    return $result;
}

function debug(string $message = '', ?array $array = null): void
{
    // Trace: 0 = msg, 1 = Aufrufer
    $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2);
    $caller = $trace[1] ?? null;
    $runtime = microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];

    if ($caller) {
        $function = $caller['function'] ?? 'undefined';
        $class = $caller['class'] ?? '';
        $file = $trace[0]['file'] ?? 'unknown';
        $line = $trace[0]['line'] ?? 'nan';
    } else {
        $function = 'Direkt';
        $class = '';
        $file = '';
        $line = '';
    }
    $argsOutput = '';
    if ($caller && !empty($caller['args'])) {
        $argsArray = array_map(fn($a) => var_export($a, true), $caller['args']);
        $argsOutput = implode(', ', $argsArray);
    }

    // HTML-Debugbox
    ?>
        <div style="
            background-color:#f5f5f5;
            border-left:4px solid #007bff;
            padding:10px 14px;
            margin:5px 0;
            font-family:monospace;
            color:#333;
            font-size:0.9em;
        ">
            <?php
                // Aufrufer-Info
                echo "<strong>Debug:</strong> ";
                if ($class) {
                    echo "<span style='color:#20c997'>{$class}::{$function}()</span>";
                } else {
                    echo "<span style='color:#20c997'>{$function}</span>";
                }
                if ($argsOutput) {
                    echo "<br><small>Argumente: <em>" . htmlspecialchars($argsOutput) . "</em></small>";
                }
                if ($file) {
                    echo "<br><small>in Datei <em>{$file}</em> Zeile <em>{$line}</em></small>";
                }
                // Nachricht
                echo "<div>Runtime: " . htmlspecialchars(number_format($runtime, 4)) . "</div>";
            ?>
            <?php if (!empty($array)): ?>
                <div style="margin-top:6px; padding:6px; background-color:#e9ecef; border-radius:4px;">
                    <?php
                        echo '<pre>';
                            print_r($array);
                        echo '</pre>';
                    ?>
                </div>
            <?php endif; ?>
            <div style="margin-top:6px; padding:6px; background-color:#e9ecef; border-radius:4px;">
                <?php echo htmlspecialchars($message); ?>
            </div>
        </div>
    <?php
}

function setOwnExceptionHandler()
{
    // Gemeinsame Ausgabe-Funktion für alle Fehlerarten
    $renderError = function($type, $message, $file, $line, $trace = null) {
        // Fehler-Typ lesbar machen
        $typeNames = [
            E_ERROR             => 'Fatal Error',
            E_WARNING           => 'Warning',
            E_PARSE             => 'Parse Error',
            E_NOTICE            => 'Notice',
            E_CORE_ERROR        => 'Core Error',
            E_CORE_WARNING      => 'Core Warning',
            E_COMPILE_ERROR     => 'Compile Error',
            E_COMPILE_WARNING   => 'Compile Warning',
            E_USER_ERROR        => 'User Error',
            E_USER_WARNING      => 'User Warning',
            E_USER_NOTICE       => 'User Notice',
            //E_STRICT            => 'Strict Notice',
            E_RECOVERABLE_ERROR => 'Recoverable Error',
            E_DEPRECATED        => 'Deprecated',
            E_USER_DEPRECATED   => 'User Deprecated',
        ];

        $typeLabel = $typeNames[$type] ?? 'Unknown Error';
        $bgColor = '#f8d7da';
        $border  = '#dc3545';
        $runtime = microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];
        echo '<div style="
            background-color:' . $bgColor . ';
            border-left:4px solid ' . $border . ';
            padding:10px;
            font-family:monospace;
            margin:10px 0;
        ">';
        echo "<strong>{$typeLabel}:</strong> " . htmlspecialchars($message) . "<br>";
        echo "<small>in Datei: " . htmlspecialchars($file) . " Zeile " . intval($line) . "</small><br>";
        echo "<div>Runtime: " . htmlspecialchars(number_format($runtime, 4)) . "</div>";


        if ($trace) {
            echo "<pre>" . htmlspecialchars($trace) . "</pre>";
        }
        echo '</div>';
    };

    // Exceptions abfangen
    set_exception_handler(function(\Throwable $e) use ($renderError) {
        $renderError(E_ERROR, $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    });

    // Warnungen, Notices, usw. abfangen
    set_error_handler(function($errno, $errstr, $errfile, $errline) use ($renderError) {
        // Standard-Fehlerbehandlung ignorieren, wenn error_reporting ausgeschaltet ist
        if (!(error_reporting() & $errno)) {
            return false;
        }
        $renderError($errno, $errstr, $errfile, $errline);
        // false zurückgeben, um Standard-Handler nicht zu blockieren
        return true;
    });

    // Fatal Errors (Shutdown)
    register_shutdown_function(function() use ($renderError) {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $renderError($error['type'], $error['message'], $error['file'], $error['line']);
        }
    });
}
