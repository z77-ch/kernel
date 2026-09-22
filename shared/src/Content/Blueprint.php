<?php

namespace Z77\Shared\Content;

/**
 * The fixed block structure of one content slug (ADR-044): an ordered list of
 * slots `{key, type, label}`. Declared in code (module config
 * `contentBlueprints`), collected by {@see ContentExtensions}.
 *
 * Two jobs, both pure:
 *   - arrange(): the stored blocks laid out slot by slot for the editor;
 *   - enforce(): what is actually saved — one block per slot in slot order, with
 *     `type` and `key` taken from the slot, whatever the posted body says.
 *
 * A stored block whose key matches no slot (or whose type no longer matches its
 * slot) is an orphan: kept verbatim, never silently dropped, never taken from a
 * posted body.
 */
final class Blueprint
{
    /** @var list<array{key:string, type:string, label:string}> */
    private array $slots = [];

    /**
     * @param list<array<string, mixed>> $slots
     */
    public function __construct(private string $slug, array $slots)
    {
        foreach ($slots as $slot) {
            $key  = (string)($slot['key'] ?? '');
            $type = (string)($slot['type'] ?? '');
            if ($key === '' || $type === '') {
                throw new \InvalidArgumentException("Blueprint '{$slug}': every slot needs a key and a type.");
            }
            if ($this->slot($key) !== null) {
                throw new \InvalidArgumentException("Blueprint '{$slug}': duplicate slot key '{$key}'.");
            }
            $this->slots[] = ['key' => $key, 'type' => $type, 'label' => (string)($slot['label'] ?? $key)];
        }
    }

    public function slug(): string
    {
        return $this->slug;
    }

    /** @return list<array{key:string, type:string, label:string}> */
    public function slots(): array
    {
        return $this->slots;
    }

    /** @return array{key:string, type:string, label:string}|null */
    public function slot(string $key): ?array
    {
        foreach ($this->slots as $slot) {
            if ($slot['key'] === $key) {
                return $slot;
            }
        }
        return null;
    }

    /**
     * Stored blocks laid out for the editor: one row per slot (its stored block,
     * or a fresh one from the type's defaults), then the orphans (`slot` null).
     *
     * @param array<int, mixed>                          $blocks  stored blocks
     * @param array<string, list<array<string, mixed>>>  $schemas type ⇒ descriptors
     * @return list<array{slot: array{key:string, type:string, label:string}|null, block: array<string, mixed>}>
     */
    public function arrange(array $blocks, array $schemas): array
    {
        $rows = [];
        foreach ($this->slots as $slot) {
            $rows[] = [
                'slot'  => $slot,
                'block' => $this->matching($blocks, $slot) ?? self::fresh($slot, $schemas[$slot['type']] ?? []),
            ];
        }
        foreach ($this->orphans($blocks) as $orphan) {
            $rows[] = ['slot' => null, 'block' => $orphan];
        }
        return $rows;
    }

    /**
     * The blocks to save. Per slot: the posted block with that key (fields from
     * the post, `type`/`key` from the slot); without one, the stored block; without
     * that, a fresh block. Orphans come from the STORED blocks only, so a crafted
     * body can neither add a block nor remove one.
     *
     * @param array<int, mixed>                          $posted
     * @param array<int, mixed>                          $stored
     * @param array<string, list<array<string, mixed>>>  $schemas
     * @return list<array<string, mixed>>
     */
    public function enforce(array $posted, array $stored, array $schemas): array
    {
        $out = [];
        foreach ($this->slots as $slot) {
            $block = self::byKey($posted, $slot['key'])
                ?? $this->matching($stored, $slot)
                ?? self::fresh($slot, $schemas[$slot['type']] ?? []);

            $block['type'] = $slot['type'];
            $block['key']  = $slot['key'];
            $out[] = $block;
        }
        foreach ($this->orphans($stored) as $orphan) {
            $out[] = $orphan;
        }
        return $out;
    }

    /**
     * The blocks to save when ONE slot was edited (the page editor, ADR-045 §4):
     * {@see enforce()} with only the posted block of $key taken from the post.
     * Every other slot and every orphan comes from the STORED blocks, so a
     * crafted body carrying other slots changes nothing but $key.
     *
     * @param array<int, mixed>                          $posted
     * @param array<int, mixed>                          $stored
     * @param array<string, list<array<string, mixed>>>  $schemas
     * @return list<array<string, mixed>>
     * @throws \InvalidArgumentException if $key is not a slot of this blueprint
     */
    public function enforceSlot(string $key, array $posted, array $stored, array $schemas): array
    {
        if ($this->slot($key) === null) {
            throw new \InvalidArgumentException("Blueprint '{$this->slug}': no slot '{$key}'.");
        }
        $block = self::byKey($posted, $key);

        return $this->enforce($block !== null ? [$block] : [], $stored, $schemas);
    }

    /** The stored block bound to $slot: same key AND same type. */
    private function matching(array $blocks, array $slot): ?array
    {
        $block = self::byKey($blocks, $slot['key']);
        return ($block !== null && ($block['type'] ?? null) === $slot['type']) ? $block : null;
    }

    /** @return list<array<string, mixed>> */
    private function orphans(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $slot = $this->slot((string)($block['key'] ?? ''));
            if ($slot === null || ($block['type'] ?? null) !== $slot['type']) {
                $out[] = $block;
            }
        }
        return $out;
    }

    private static function byKey(array $blocks, string $key): ?array
    {
        foreach ($blocks as $block) {
            if (is_array($block) && (string)($block['key'] ?? '') === $key) {
                return $block;
            }
        }
        return null;
    }

    /**
     * A new block for $slot, every field at its schema default.
     *
     * @param list<array<string, mixed>> $schema
     * @return array<string, mixed>
     */
    public static function fresh(array $slot, array $schema): array
    {
        $block = ['type' => $slot['type'], 'key' => $slot['key']];
        foreach ($schema as $field) {
            $key = (string)($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $kind = (string)($field['kind'] ?? 'text');
            $block[$key] = $field['default'] ?? match ($kind) {
                'list'  => [],
                'bool'  => false,
                default => '',
            };
        }
        return $block;
    }
}
