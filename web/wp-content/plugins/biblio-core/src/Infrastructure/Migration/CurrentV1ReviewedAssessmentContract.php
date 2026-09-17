<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Exception\ValidationException;

/** Exact reviewed Rating/Review/Reflection contract from docs/128. */
final readonly class CurrentV1ReviewedAssessmentContract
{
    public const VERSION = "d-mig-assess-map-01.2026-09-17";
    public const MANIFEST_SHA256 =
        "35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67";

    public function __construct(private string $manifestSha256 = self::MANIFEST_SHA256)
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $this->manifestSha256) !== 1) {
            throw new ValidationException("Assessment mapping manifest digest is invalid.");
        }
    }

    public function manifestSha256(): string { return $this->manifestSha256; }
    public function identity(): string
    {
        return self::VERSION . ":" . substr($this->manifestSha256, 0, 24);
    }
}
