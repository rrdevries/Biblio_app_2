<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use InvalidArgumentException;

final readonly class MetadataCandidateId
{
    public function __construct(private string $value)
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new InvalidArgumentException("Invalid metadata candidate ID.");
        }
    }

    public static function fromCandidate(MetadataCandidate $candidate): self
    {
        return new self(hash("sha256", implode("\0", [
            $candidate->providerKey(),
            $candidate->providerRecordId(),
            $candidate->returnedIsbn()->isbn13()->value(),
        ])));
    }

    public function value(): string { return $this->value; }
}
