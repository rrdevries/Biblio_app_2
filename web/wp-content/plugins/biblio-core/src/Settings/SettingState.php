<?php

declare(strict_types=1);
namespace Biblio\Core\Settings;

final readonly class SettingState
{
    public function __construct(public string|bool|null $value, public int $version) {}
    public function projection(string|bool $fallback, string $fallbackSource): array
    {
        return ['value'=>$this->value,'version'=>$this->version,
            'effective'=>$this->value ?? $fallback,'source'=>$this->value === null ? $fallbackSource : 'personal'];
    }
}
