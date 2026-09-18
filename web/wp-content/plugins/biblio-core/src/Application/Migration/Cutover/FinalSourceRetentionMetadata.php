<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

use Biblio\Core\Exception\ValidationException;

final readonly class FinalSourceRetentionMetadata
{
    public function __construct(
        private string $retainedPackageId,
        private string $independentCopyState,
        private string $recoveryVerificationState
    ) {
        if (trim($this->retainedPackageId) === "" || mb_strlen($this->retainedPackageId) > 191) {
            throw new ValidationException("Retained package identifier is invalid.");
        }
        $states = ["not_verified", "verified", "failed"];
        if (
            !in_array($this->independentCopyState, $states, true)
            || !in_array($this->recoveryVerificationState, $states, true)
        ) {
            throw new ValidationException("Final source retention state is invalid.");
        }
    }

    /** @return array<string,string> */
    public function toArray(): array
    {
        return [
            "retained_package_id" => $this->retainedPackageId,
            "independent_copy_verification" => $this->independentCopyState,
            "recovery_verification" => $this->recoveryVerificationState,
        ];
    }
}
