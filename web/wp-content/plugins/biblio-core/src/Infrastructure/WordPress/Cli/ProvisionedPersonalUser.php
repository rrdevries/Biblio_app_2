<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Cli;

use Biblio\Core\Identity\UserId;

final readonly class ProvisionedPersonalUser
{
    public function __construct(
        private UserId $userId,
        private string $login,
        private bool $created
    ) {
    }

    public function userId(): UserId { return $this->userId; }
    public function login(): string { return $this->login; }
    public function wasCreated(): bool { return $this->created; }
}
