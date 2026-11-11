<?php

namespace Z77\Shared\Content;

use Z77\Core\DI;

/**
 * The project side of structured content (ADR-044), collected from every
 * module's config:
 *
 *   'contentBlueprints' => ['faq' => [['key' => 'intro', 'type' => 'intro', 'label' => '…'], …]],
 *   'contentActions'    => ['contact' => ['href' => '/kontakt', 'attributes' => ['data-apply-open' => '']]],
 *   'contentDocuments'  => \Z77\Module\Dms\Content\ContentDocuments::class,   // at most ONE, {@see DocumentReferences}
 *
 * Assembled on demand like BlockRegistry — NOT a DI service (ADR-012). A slug or
 * an action declared by two modules is a configuration error and throws: which
 * one would win is not visible anywhere.
 */
final class ContentExtensions
{
    /**
     * @param array<string, Blueprint> $blueprints slug ⇒ blueprint
     * @param array<string, array{href?:string, attributes?:array<string,string>}> $actions
     */
    public function __construct(
        private array $blueprints = [],
        private array $actions = [],
        private ?DocumentReferences $documents = null
    ) {}

    public static function assemble(): self
    {
        $blueprints = [];
        $actions    = [];
        $documents  = null;

        $moduleManager = DI::getModuleManager();
        foreach ($moduleManager->getModuleKeys() as $moduleKey) {
            $config = $moduleManager->getModuleConfig($moduleKey);
            if ($config === null) {
                continue;
            }
            foreach ((array)$config->get('contentBlueprints', []) as $slug => $slots) {
                if (isset($blueprints[$slug])) {
                    throw new \LogicException("contentBlueprints: slug '{$slug}' is declared twice (module '{$moduleKey}').");
                }
                $blueprints[$slug] = new Blueprint((string)$slug, (array)$slots);
            }
            foreach ((array)$config->get('contentActions', []) as $name => $action) {
                if (isset($actions[$name])) {
                    throw new \LogicException("contentActions: action '{$name}' is declared twice (module '{$moduleKey}').");
                }
                $actions[$name] = (array)$action;
            }
            $class = $config->get('contentDocuments');
            if (is_string($class) && $class !== '') {
                if ($documents !== null) {
                    throw new \LogicException("contentDocuments: declared twice (module '{$moduleKey}') — one asset store answers for document fields.");
                }
                $documents = new $class();
                if (!$documents instanceof DocumentReferences) {
                    throw new \LogicException("contentDocuments: {$class} (module '{$moduleKey}') does not implement DocumentReferences.");
                }
            }
        }

        return new self($blueprints, $actions, $documents);
    }

    public function blueprint(string $slug): ?Blueprint
    {
        return $this->blueprints[$slug] ?? null;
    }

    /** @return array<string, Blueprint> */
    public function blueprints(): array
    {
        return $this->blueprints;
    }

    /** @return array<string, array{href?:string, attributes?:array<string,string>}> */
    public function actions(): array
    {
        return $this->actions;
    }

    /** The asset store behind `document` block fields, or null (then only the id's shape is checked). */
    public function documents(): ?DocumentReferences
    {
        return $this->documents;
    }
}
