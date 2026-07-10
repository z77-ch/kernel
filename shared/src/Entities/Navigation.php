<?php

namespace Z77\Shared\Entities;

use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;
use Z77\Shared\Tree\TreeNode;
use Z77\Shared\Tree\TreeNodeTrait;

#[Entity('file', 'framework/routing/navigation.json', invalidatesCache: true)]
class Navigation implements TreeNode
{
    use ArrayMappable;

    // sortKey + parentId (server-controlled tree fields) come from the trait.
    // For Navigation: parentId null = top-level tree-root; sortKey orders
    // siblings (children of one parent, or tree-roots carrying one slot).
    use TreeNodeTrait;

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    private ?int $id = null;

    #[Clean('text')]
    private string $name = '';

    #[Clean('ident')]
    private string $module = '';

    #[Clean('ident')]
    private string $group = '';

    #[Clean('ident')]
    private string $controller = '';

    #[Clean('ident')]
    private string $action = '';

    /**
     * Render-slot slug this tree-root attaches to (e.g. `frontend-meta`); empty
     * for child nodes. The slot set is CONFIG (ModuleManager `navSlots`, ADR-022),
     * not an entity — this references a config constant, so it is a slug string,
     * not an FK. The slot XOR parentId invariant is enforced by NavigationValidator.
     */
    #[Clean('ident')]
    private string $slot = '';

    #[Clean('nullable', 'int')]
    private ?int $ref = null;

    #[Clean('bool')]
    private bool $active = true;

    /**
     * Optional UI-state query fragment appended to the entry's generated href by
     * {@see \Z77\Core\Services\NavigationUrlResolver::urlFor} (e.g. `key=front` → `?key=front`).
     * It is a SWITCH TRIGGER for target-side session state (analogous to `?via=` for refs),
     * NOT a routing discriminator: `findByPath`/`resolveCurrent` match the 4-tuple only and
     * ignore it, so it does NOT resurrect the ADR-015-removed `params` routing mechanism.
     */
    #[Clean('text')]
    private string $param = '';

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }

    /**
     * Since Phase 4 (ADR-015) Navigation owns no url/params — the public URL lives
     * in {@see NavigationAlias}. This returns the canonical 4-tuple path, used by
     * NavigationService::urlFor() as the fallback when an entry has no alias (e.g.
     * backend convention routes) and as the navigability marker ('' = container).
     */
    public function getUrl(): string
    {
        return $this->getCanonicalPath();
    }

    public function getCanonicalUrl(): string
    {
        return $this->getCanonicalPath();
    }

    public function getCanonicalPath(): string
    {
        if ($this->module === '') return '';
        return '/' . $this->module . '/' . $this->group . '/' . $this->controller . '/' . $this->action;
    }

    public function getModule(): string { return $this->module; }
    public function getGroup(): string { return $this->group; }
    public function getController(): string { return $this->controller; }
    public function getAction(): string { return $this->action; }
    public function getSlot(): string { return $this->slot; }
    public function getRef(): ?int { return $this->ref; }
    public function isActive(): bool { return $this->active; }
    public function getParam(): string { return $this->param; }

    public function setName(string $name): void { $this->name = $name; }

    public function setModule(string $module): void { $this->module = $module; }
    public function setGroup(string $group): void { $this->group = $group; }
    public function setController(string $controller): void { $this->controller = $controller; }
    public function setAction(string $action): void { $this->action = $action; }

    /** Render-slot slug (empty = child node). Server-controlled by add/move logic. */
    public function setSlot(string $slot): void { $this->slot = trim($slot); }

    public function setRef(?int $ref): void
    {
        $this->ref = ($ref === null || $ref <= 0) ? null : $ref;
    }

    public function setActive(bool $active): void { $this->active = $active; }

    /** Bare query fragment (a leading `?`/`&` and whitespace are stripped); `urlFor` appends it. */
    public function setParam(string $param): void
    {
        $this->param = ltrim(preg_replace('/\s+/', '', $param) ?? '', '?&');
    }
}
