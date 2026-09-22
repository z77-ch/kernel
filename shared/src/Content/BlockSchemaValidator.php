<?php

namespace Z77\Shared\Content;

/**
 * Checks one block's values against its type's field schema (ADR-044):
 * `required`, `maxLength` (scalars), `min` / `max` (lists, also per object-list
 * row field), and the URL scheme of `kind: url` fields.
 *
 * Returns messages, it does not throw: the caller (ContentValidator) reports them
 * as form errors. Messages are German — they are shown in the backend editor.
 */
final class BlockSchemaValidator
{
    /**
     * @param array<string, mixed>             $block   {type, key?, ...fields}
     * @param list<array<string, mixed>>        $schema  descriptors from BlockRenderer::schema()
     * @param string                            $context prefix for every message (e.g. the slot label)
     * @return list<string>
     */
    public function errors(array $block, array $schema, string $context = ''): array
    {
        $prefix = $context === '' ? '' : $context . ': ';
        $errors = [];

        foreach ($schema as $field) {
            $key   = (string)($field['key'] ?? '');
            $label = (string)($field['label'] ?? $key);
            if ($key === '') {
                continue;
            }
            foreach ($this->fieldErrors($field, $block[$key] ?? null) as $message) {
                $errors[] = $prefix . '«' . $label . '» ' . $message;
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private function fieldErrors(array $field, mixed $value): array
    {
        $kind = (string)($field['kind'] ?? 'text');

        if ($kind === 'list') {
            return $this->listErrors($field, $value);
        }
        if ($kind === 'bool' || $kind === 'select') {
            return [];
        }

        $text = is_scalar($value) ? trim((string)$value) : '';
        $out  = [];

        if (!empty($field['required']) && $text === '') {
            $out[] = 'darf nicht leer sein.';
        }
        $max = (int)($field['maxLength'] ?? 0);
        if ($max > 0 && mb_strlen($text) > $max) {
            $out[] = 'ist zu lang (höchstens ' . $max . ' Zeichen).';
        }
        if ($kind === 'url' && $text !== '' && !$this->safeUrl($text)) {
            $out[] = 'ist keine erlaubte Adresse (/…, #…, http(s)://, mailto:).';
        }

        return $out;
    }

    /** @return list<string> */
    private function listErrors(array $field, mixed $value): array
    {
        $rows  = is_array($value) ? array_values($value) : [];
        $count = count($rows);
        $out   = [];

        $min = (int)($field['min'] ?? 0);
        $max = (int)($field['max'] ?? 0);
        if (!empty($field['required']) && $min < 1) {
            $min = 1;
        }
        if ($count < $min) {
            $out[] = 'braucht mindestens ' . $min . ' Einträge.';
        }
        if ($max > 0 && $count > $max) {
            $out[] = 'erlaubt höchstens ' . $max . ' Einträge.';
        }

        $item = $field['item'] ?? 'text';
        if (is_array($item)) {
            foreach ($rows as $i => $row) {
                $row = is_array($row) ? $row : [];
                foreach ($item as $sub) {
                    $subKey = (string)($sub['key'] ?? '');
                    foreach ($this->fieldErrors($sub, $row[$subKey] ?? null) as $message) {
                        $out[] = 'Eintrag ' . ($i + 1) . ', «' . (string)($sub['label'] ?? $subKey) . '» ' . $message;
                    }
                }
            }
        } else {
            $maxLength = (int)($field['maxLength'] ?? 0);
            foreach ($rows as $i => $row) {
                if ($maxLength > 0 && mb_strlen(trim((string)$row)) > $maxLength) {
                    $out[] = 'Eintrag ' . ($i + 1) . ' ist zu lang (höchstens ' . $maxLength . ' Zeichen).';
                }
            }
        }

        return $out;
    }

    /** Same gate as a link target: site-relative (not //), http(s), mailto. */
    private function safeUrl(string $url): bool
    {
        if (str_starts_with($url, '//')) {
            return false;
        }
        if ($url[0] === '/' || $url[0] === '#') {
            return true;
        }
        $lower = strtolower($url);
        return str_starts_with($lower, 'http://') || str_starts_with($lower, 'https://')
            || str_starts_with($lower, 'mailto:');
    }
}
