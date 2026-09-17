<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Preservation;

use Biblio\Core\Exception\ValidationException;

final readonly class PreservedSourceEvidenceAdmission
{
    public function __construct(
        private string $evidenceType,
        private string $reasonCode,
        private PreservedSourceEvidencePrivacy $privacy
    ) {
        foreach ([$this->evidenceType, $this->reasonCode] as $token) {
            if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $token) !== 1) {
                throw new ValidationException("Preserved evidence admission is invalid.");
            }
        }
    }

    public function evidenceType(): string { return $this->evidenceType; }
    public function reasonCode(): string { return $this->reasonCode; }
    public function privacy(): PreservedSourceEvidencePrivacy { return $this->privacy; }
}
