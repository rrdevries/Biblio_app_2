<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

use Biblio\Core\Exception\ValidationException;

final readonly class MigrationSourceFinding
{
    public function __construct(
        private string $reasonCode,
        private string $location,
        private string $message
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $this->reasonCode) !== 1) {
            throw new ValidationException("Source finding reason is invalid.");
        }
        if (trim($this->location) === "" || trim($this->message) === "") {
            throw new ValidationException("Source finding is incomplete.");
        }
    }

    /** @return array{reason_code:string,location:string,message:string} */
    public function toArray(): array
    {
        return [
            "reason_code" => $this->reasonCode,
            "location" => $this->location,
            "message" => $this->message,
        ];
    }
}
