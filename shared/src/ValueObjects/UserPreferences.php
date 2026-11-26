<?php
namespace Z77\Shared\ValueObjects;

final class UserPreferences
{
    /** The backend palette of a user who has not picked one (ADR-051 addendum 2026-10-17). */
    public const DEFAULT_PALETTE = 'salbei';

    /**
     * The backend font slider's range (FONT-SCALE-LINEAR-001): 85–125 %, default 100 % in the
     * middle. The slider multiplies the root font size once, so 1.25 = every font 25 % larger.
     */
    public const FONT_SCALE_MIN = 0.85;
    public const FONT_SCALE_MAX = 1.25;

    private string $palette   = self::DEFAULT_PALETTE;
    private bool   $darkMode  = false;
    private float  $fontScale = 1.0;

    /** @var array<string, bool> partial-label overlay per view area (absent = off) */
    private array $partialLabels = [];

    /**
     * @var array<string, bool> page editor switch per view area (absent = off,
     * ADR-045 §4 addendum): markers and pencils on the page only when on
     */
    private array $contentEdit = [];

    public function __construct(array $data = [])
    {
        $this->palette   = $data['palette']    ?? self::DEFAULT_PALETTE;
        $this->darkMode  = (bool) ($data['dark_mode']  ?? false);
        $this->fontScale = self::clampFontScale((float) ($data['font_scale'] ?? 1.0));

        foreach ((array) ($data['partial_labels'] ?? []) as $viewArea => $on) {
            $this->partialLabels[(string) $viewArea] = (bool) $on;
        }
        foreach ((array) ($data['content_edit'] ?? []) as $viewArea => $on) {
            $this->contentEdit[(string) $viewArea] = (bool) $on;
        }
    }

    public function getPalette(): string  { return $this->palette; }
    public function isDarkMode(): bool    { return $this->darkMode; }
    public function getFontScale(): float { return $this->fontScale; }

    public function isPartialLabelsEnabled(string $viewArea): bool
    {
        return $this->partialLabels[$viewArea] ?? false;
    }

    public function isContentEditEnabled(string $viewArea): bool
    {
        return $this->contentEdit[$viewArea] ?? false;
    }

    public function setPalette(string $palette): void  { $this->palette   = $palette; }
    public function setDarkMode(bool $dark): void      { $this->darkMode  = $dark; }
    public function setFontScale(float $scale): void   { $this->fontScale = self::clampFontScale($scale); }

    /**
     * Into the slider's range, rounded to its 5 % step. Applied on read as well as on write: the
     * value is rendered into `style="--be-font-scale: …"` of every backend page, so a stored value
     * outside the range (or from the old 1.0–1.4 range) never reaches the page.
     */
    private static function clampFontScale(float $scale): float
    {
        if (!is_finite($scale)) {
            return 1.0;
        }
        $scale = max(self::FONT_SCALE_MIN, min(self::FONT_SCALE_MAX, $scale));
        return round($scale * 20) / 20;
    }

    public function setPartialLabelsEnabled(string $viewArea, bool $on): void
    {
        if ($on) {
            $this->partialLabels[$viewArea] = true;
        } else {
            // Deviation-only storage: off = key absent, not `false`.
            unset($this->partialLabels[$viewArea]);
        }
    }

    public function setContentEditEnabled(string $viewArea, bool $on): void
    {
        if ($on) {
            $this->contentEdit[$viewArea] = true;
        } else {
            // Deviation-only storage, like partial labels.
            unset($this->contentEdit[$viewArea]);
        }
    }

    public function toArray(): array
    {
        $data = [
            'palette'    => $this->palette,
            'dark_mode'  => $this->darkMode,
            'font_scale' => $this->fontScale,
        ];
        if ($this->partialLabels !== []) {
            $data['partial_labels'] = $this->partialLabels;
        }
        if ($this->contentEdit !== []) {
            $data['content_edit'] = $this->contentEdit;
        }
        return $data;
    }
}
