<?php

namespace Z77\Shared\Content;

/**
 * Checks one block's values against its type's field schema (ADR-044):
 * `required`, `maxLength` (scalars), `min` / `max` (lists, also per object-list
 * row field), the URL scheme of `kind: url` fields, and the SHAPE of
 * `kind: document` fields (a positive integer id — whether that document exists
 * and is public is the renderer's and the picker's business: `shared` knows
 * nothing of the DMS). With a {@see DocumentReferences} (the asset store's answer,
 * declared by its module) a CHANGED id is also asked whether it may stand there —
 * an id in `$storedIds` (already in the saved document) is not asked again.
 *
 * Returns messages, it does not throw: the caller (ContentValidator) reports them
 * as form errors. Messages are German — they are shown in the backend editor.
 */
final class BlockSchemaValidator
{
    private const NOT_A_DOCUMENT = 'ist keine gültige Dokument-Nummer.';

    /** @var array<int, true> */
    private array $known;

    /**
     * @param DocumentReferences|null $documents the store's check of a document id (null: shape only)
     * @param list<int>               $storedIds ids the stored document already references — not asked
     */
    public function __construct(
        private ?DocumentReferences $documents = null,
        array $storedIds = [],
    ) {
        $this->known = array_fill_keys($storedIds, true);
    }

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
        if ($kind === 'document') {
            return $this->documentErrors($field, $value);
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
        } elseif ($item === 'document') {
            foreach ($rows as $i => $row) {
                $id = self::documentId($row);
                if ($id === null) {
                    $out[] = 'Eintrag ' . ($i + 1) . ' ' . self::NOT_A_DOCUMENT;
                } elseif (($refusal = $this->refusal($id, $field)) !== null) {
                    $out[] = 'Eintrag ' . ($i + 1) . ' ' . $refusal;
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

    /**
     * The ONE reading of a `document` field value: a positive integer id, stored as
     * an int or as its plain digit string (the block editor serialises every input
     * as a string). Anything else — `0`, a sign, a fraction, blanks, letters, a
     * leading zero, an array — is no id. A renderer resolving the id reads the
     * value through this too, so validator and render agree on what an id is.
     */
    public static function documentId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/', $value) === 1) {
            return (int)$value;
        }

        return null;
    }

    /**
     * Shape only: empty is allowed unless `required`; a value must be an id
     * ({@see documentId()}).
     *
     * @return list<string>
     */
    private function documentErrors(array $field, mixed $value): array
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return !empty($field['required']) ? ['darf nicht leer sein.'] : [];
        }

        $id = self::documentId($value);
        if ($id === null) {
            return [self::NOT_A_DOCUMENT];
        }
        $refusal = $this->refusal($id, $field);

        return $refusal === null ? [] : [$refusal];
    }

    /** The store's answer for a CHANGED id; a stored one, or no store, is not asked. */
    private function refusal(int $id, array $field): ?string
    {
        return $this->documents === null || isset($this->known[$id]) ? null : $this->documents->refusal($id, $field);
    }

    /**
     * Every document id the `document` fields of $blocks name (top level, object-list rows and
     * scalar `item: document` lists) — what a save passes as `$storedIds` from the STORED blocks.
     *
     * @param list<array<string, mixed>>                 $blocks
     * @param array<string, list<array<string, mixed>>>  $schemas type ⇒ field descriptors
     * @return list<int>
     */
    public static function documentIds(array $blocks, array $schemas): array
    {
        $ids  = [];
        $walk = static function (array $fields, array $values) use (&$walk, &$ids): void {
            foreach ($fields as $field) {
                $key   = (string)($field['key'] ?? '');
                $kind  = (string)($field['kind'] ?? 'text');
                $value = $values[$key] ?? null;
                if ($kind === 'document' && ($id = self::documentId($value)) !== null) {
                    $ids[] = $id;
                } elseif ($kind === 'list' && is_array($value)) {
                    $item = $field['item'] ?? 'text';
                    foreach ($value as $row) {
                        if (is_array($item) && is_array($row)) {
                            $walk($item, $row);
                        } elseif ($item === 'document' && ($id = self::documentId($row)) !== null) {
                            $ids[] = $id;
                        }
                    }
                }
            }
        };
        foreach ($blocks as $block) {
            if (is_array($block) && isset($schemas[(string)($block['type'] ?? '')])) {
                $walk($schemas[(string)$block['type']], $block);
            }
        }

        return array_values(array_unique($ids));
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
