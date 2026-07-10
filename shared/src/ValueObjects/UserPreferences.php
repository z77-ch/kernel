<?php
namespace Z77\Shared\ValueObjects;

final class UserPreferences
{
    private string $palette   = 'werkbank';
    private bool   $darkMode  = false;
    private float  $fontScale = 1.0;

    public function __construct(array $data = [])
    {
        $this->palette   = $data['palette']    ?? 'werkbank';
        $this->darkMode  = (bool) ($data['dark_mode']  ?? false);
        $this->fontScale = (float) ($data['font_scale'] ?? 1.0);
        $this->fontScale = max(1.0, min(1.4, $this->fontScale));
    }

    public function getPalette(): string  { return $this->palette; }
    public function isDarkMode(): bool    { return $this->darkMode; }
    public function getFontScale(): float { return $this->fontScale; }

    public function setPalette(string $palette): void  { $this->palette   = $palette; }
    public function setDarkMode(bool $dark): void      { $this->darkMode  = $dark; }
    public function setFontScale(float $scale): void   { $this->fontScale = max(1.0, min(1.4, $scale)); }

    public function toArray(): array
    {
        return [
            'palette'    => $this->palette,
            'dark_mode'  => $this->darkMode,
            'font_scale' => $this->fontScale,
        ];
    }
}
