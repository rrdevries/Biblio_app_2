<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

use Biblio\Core\Application\Migration\Runner\DeterministicJson;

/** Single intent/environment receipt. Resume and replay reuse only this exact intent. */
final readonly class RehearsalAuthorization
{
    /**
 * @param array<string,mixed> $preflight
 * @param array<string,mixed> $backup
 */
    public function __construct(
        public array $preflight,
        public array $backup,
        public string $operatorReviewId,
        string $confirmation,
        public bool $resumePermitted,
        public RehearsalFault $fault = RehearsalFault::None
    ) {
        RehearsalContract::id($operatorReviewId);
        RehearsalContract::require($confirmation === self::confirmationFor($preflight, $backup, $resumePermitted, $fault), "explicit_authorization_missing");
        RehearsalContract::require(($backup["phase"] ?? null) === "PRE_APPLY", "pre_apply_backup_required");
    }

    public function intentDigest(): string
    {
        return DeterministicJson::hash([
            "purpose" => "rehearsal_only",
            "preflight" => $this->preflight,
            "resume_permitted" => $this->resumePermitted,
            "fault" => $this->fault->value,
        ]);
    }

    public function authorizationDigest(): string
    {
        return substr(self::confirmationFor($this->preflight, $this->backup, $this->resumePermitted, $this->fault), strlen("AUTHORIZE REHEARSAL "));
    }

    /**
 * Compute the exact confirmation before explicit operator approval.
 * @param array<string,mixed> $preflight
 * @param array<string,mixed> $backup
 */
    public static function confirmationFor(array $preflight, array $backup, bool $resumePermitted, RehearsalFault $fault = RehearsalFault::None): string
    {
        return "AUTHORIZE REHEARSAL " . DeterministicJson::hash([
            "purpose" => "rehearsal_only", "preflight" => $preflight,
            "pre_apply_backup" => $backup, "resume_permitted" => $resumePermitted, "fault" => $fault->value,
        ]);
    }
}
