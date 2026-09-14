<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

use Biblio\Core\Exception\ValidationException;

final readonly class MigrationSourceFile
{
    public function __construct(
        private string $relativePath,
        private int $byteSize,
        private string $sha256
    ) {
        if (
            $this->relativePath === ""
            || str_starts_with($this->relativePath, "/")
            || str_contains($this->relativePath, "\\")
            || in_array("..", explode("/", $this->relativePath), true)
            || preg_match('//u', $this->relativePath) !== 1
        ) {
            throw new ValidationException("Source file path is unsafe.");
        }
        if ($this->byteSize < 0) {
            throw new ValidationException("Source file size is invalid.");
        }
        if (preg_match('/^[0-9a-f]{64}$/', $this->sha256) !== 1) {
            throw new ValidationException("Source file hash is invalid.");
        }
    }

    public function relativePath(): string { return $this->relativePath; }
    public function byteSize(): int { return $this->byteSize; }
    public function sha256(): string { return $this->sha256; }

    /** @return array{relative_path:string,byte_size:int,sha256:string} */
    public function toArray(): array
    {
        return [
            "relative_path" => $this->relativePath,
            "byte_size" => $this->byteSize,
            "sha256" => $this->sha256,
        ];
    }
}
