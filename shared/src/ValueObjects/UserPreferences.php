<?php
namespace Z77\Shared\ValueObjects;

final class UserPreferences
{
    private string $palette   = 'werkbank';
    private bool   $darkMode  = false;
    private float  $fontScale = 1.0;

    /** @var array<string, bool> partial-label overlay per view area (absent = off) */
    private array $partialLabels = [];

    public function __construct(array $data = [])
    {
        $this->palette   = $data['palette']    ?? 'werkbank';
        $this->darkMode  = (bool) ($data['dark_mode']  ?? false);
        $this->fontScale = (float) ($data['font_scale'] ?? 1.0);
        $this->fontScale = max(1.0, min(1.4, $this->fontScale));

        foreach ((array) ($data['partial_labels'] ?? []) as $viewArea => $on) {
            $this->partialLabels[(string) $viewArea] = (bool) $on;
        }
    }

    public function getPalette(): string  { return $this->palette; }
    public function isDarkMode(): bool    { return $this->darkMode; }
    public function getFontScale(): float { return $this->fontScale; }

    public function isPartialLabelsEnabled(string $viewArea): bool
    {
        return $this->partialLabels[$viewArea] ?? false;
    }

    public function setPalette(string $palette): void  { $this->palette   = $palette; }
    public function setDarkMode(bool $dark): void      { $this->darkMode  = $dark; }
    public function setFontScale(float $scale): void   { $this->fontScale = max(1.0, min(1.4, $scale)); }

    public function setPartialLabelsEnabled(string $viewArea, bool $on): void
    {
        if ($on) {
            $this->partialLabels[$viewArea] = true;
        } else {
            // Deviation-only storage: off = key absent, not `false`.
            unset($this->partialLabels[$viewArea]);
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
        return $data;
    }
}
