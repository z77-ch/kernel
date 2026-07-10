<?php

namespace Z77\Shared\Entities;

use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * Per-page SEO metadata. Identity is (navigationId, language) — one record per
 * navigation entry and language. The read path is wired through
 * {@see \Z77\Core\Services\NavigationService::findMetaData()} and injected into
 * templates as `$metaData` by {@see \Z77\Core\Controller\AbstractBaseController::html()}.
 * The write path (backend CRUD) lives in the backend MetaDataController.
 *
 * `id` is server-controlled (no setter) — assigned by the FileRepository on
 * persist and hydrated via reflection on load.
 */
#[Entity('file', 'framework/seo/metadata.json', invalidatesCache: true)]
class MetaData
{
    use ArrayMappable;

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    private ?int $id = null;

    /** FK to the {@see Navigation} entry this metadata describes. Part of the identity. */
    #[Clean('int')]
    private ?int $navigationId = null;

    /** Language code (e.g. `de`). Part of the identity. */
    #[Clean('ident')]
    private string $language = '';

    #[Clean('text')]
    private string $title = '';

    #[Clean('text')]
    private string $description = '';

    #[Clean('text')]
    private string $themeColor = '';

    /**
     * JSON-LD structured data, rendered into `<script type="application/ld+json">`.
     * @var array<string, mixed>
     */
    private array $applicationLd = [];

    public function getId(): ?int { return $this->id; }
    public function getNavigationId(): ?int { return $this->navigationId; }
    public function getLanguage(): string { return $this->language; }
    public function getTitle(): string { return $this->title; }
    public function getDescription(): string { return $this->description; }
    public function getThemeColor(): string { return $this->themeColor; }
    public function getApplicationLd(): array { return $this->applicationLd; }

    public function setNavigationId(?int $navigationId): void
    {
        $this->navigationId = ($navigationId === null || $navigationId <= 0) ? null : $navigationId;
    }

    public function setLanguage(string $language): void { $this->language = $language; }
    public function setTitle(string $title): void { $this->title = $title; }
    public function setDescription(string $description): void { $this->description = $description; }
    public function setThemeColor(string $themeColor): void { $this->themeColor = $themeColor; }

    /**
     * Accepts an array (from the JSON file) or a JSON-encoded string (from the
     * backend editor's textarea). Invalid JSON → empty map; the validator reports
     * the parse error separately so the user gets feedback (mirrors Content::setBlocks).
     */
    public function setApplicationLd(mixed $applicationLd): void
    {
        if (is_string($applicationLd)) {
            $applicationLd = $applicationLd === '' ? [] : json_decode($applicationLd, true);
        }
        $this->applicationLd = is_array($applicationLd) ? $applicationLd : [];
    }
}
