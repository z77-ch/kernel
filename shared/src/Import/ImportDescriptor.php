<?php

namespace Z77\Shared\Import;

use Z77\Shared\Attributes\ImportIdentity;
use Z77\Shared\Attributes\ImportNearMatch;
use Z77\Shared\Attributes\ImportRef;
use Z77\Shared\Libraries\Convention\Naming;

/**
 * Reads an entity class's import declaration (ADR-032) once and answers the
 * planner's questions about it: the ordered identity rules, the ref fields
 * with their target classes, the near-match fields, and the content-field set
 * the hash/diff runs over. All field names are exposed in snake_case — the
 * import works on `mapToArray()` shapes, the same representation the file
 * storage and the seed files use.
 *
 * Content fields = every mapped property EXCEPT `id`, `sort_key` and the ref
 * fields (compared separately via resolved counterparts). `id` is never
 * content (per-installation), `sort_key` is server-managed ordering noise
 * (IMP-R005) — both excluded by convention, not annotation.
 */
final class ImportDescriptor
{
    private const EXCLUDED_CONTENT_FIELDS = ['id', 'sort_key'];

    /** @var list<list<string>> identity rules, snake_case */
    private array $identityRules = [];

    /** @var list<string> near-match fields, snake_case ([] = pass disabled) */
    private array $nearMatch = [];

    /** @var array<string, ImportRef> snake_case field → ref declaration */
    private array $refs = [];

    /** @var list<string> content (hash/diff) fields, snake_case */
    private array $contentFields = [];

    /** @param class-string $entityClass */
    public function __construct(public readonly string $entityClass)
    {
        $ref = new \ReflectionClass($entityClass);

        $identity = $ref->getAttributes(ImportIdentity::class)[0] ?? null;
        if ($identity === null) {
            throw new \InvalidArgumentException(
                "{$entityClass} carries no #[ImportIdentity] — not importable."
            );
        }
        $this->identityRules = array_map(
            fn(array $rule) => array_map([Naming::class, 'toSnakeCase'], $rule),
            $identity->newInstance()->rules
        );

        $nearMatch = $ref->getAttributes(ImportNearMatch::class)[0] ?? null;
        if ($nearMatch !== null) {
            $this->nearMatch = array_map([Naming::class, 'toSnakeCase'], $nearMatch->newInstance()->fields);
        }

        foreach ($ref->getProperties() as $prop) {
            $attr = $prop->getAttributes(ImportRef::class)[0] ?? null;
            $key  = Naming::toSnakeCase($prop->getName());

            if ($attr !== null) {
                $declared = $attr->newInstance();
                // 'self' (used by shared traits like TreeNodeTrait, where the
                // concrete class is unknown) resolves to the described class.
                $this->refs[$key] = $declared->targetClass === 'self'
                    ? new ImportRef($entityClass, $declared->resolveBy)
                    : $declared;
                continue;
            }
            if (!in_array($key, self::EXCLUDED_CONTENT_FIELDS, true)) {
                $this->contentFields[] = $key;
            }
        }
    }

    /** @return list<list<string>> */
    public function getIdentityRules(): array { return $this->identityRules; }

    /** @return list<string> */
    public function getNearMatchFields(): array { return $this->nearMatch; }

    /** @return array<string, ImportRef> */
    public function getRefs(): array { return $this->refs; }

    public function isRefField(string $field): bool { return isset($this->refs[$field]); }

    /** @return list<string> */
    public function getContentFields(): array { return $this->contentFields; }

    /**
     * Normalizes a raw record into the canonical snake_case shape: hydrate
     * through the entity (setters apply their normalizations — e.g.
     * `Navigation::setKey` blank→null), map back out, then re-attach the id
     * (entities have no setId — ids are persistence-assigned, but the import
     * needs the SOURCE id as the in-file reference address).
     */
    public function normalize(array $raw): array
    {
        $entity = new ($this->entityClass)($raw);
        $normalized = $entity->mapToArray();
        $normalized['id'] = isset($raw['id']) && is_int($raw['id']) ? $raw['id'] : null;
        return $normalized;
    }
}
