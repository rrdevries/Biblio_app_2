<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

use Biblio\Core\Application\Migration\Runner\DeterministicJson;

/** Operator-supplied only. Neither planning nor a rehearsal approval creates this capability. */
final readonly class ProductionAuthorization
{
    /**
     * @param array<string,mixed> $packet
     * @param array<string,mixed> $approval
     */
    public function __construct(public array $packet, array $approval)
    {
        RehearsalContract::keys($approval, ["purpose", "packet_digest", "confirmation"]);
        RehearsalContract::require($approval["purpose"] === "production-cutover", "production_authorization_required");
        RehearsalContract::equal($approval["packet_digest"], self::digest($packet), "production_authorization_changed");
        RehearsalContract::require($approval["confirmation"] === "AUTHORIZE PRODUCTION " . self::digest($packet), "production_authorization_required");
        RehearsalContract::require(($packet["backup"]["phase"] ?? null) === "PRE_APPLY", "pre_apply_backup_required");
    }

    /** @param array<string,mixed> $packet */
    public static function digest(array $packet): string
    {
        return DeterministicJson::hash(["purpose" => "production-cutover", "packet" => $packet]);
    }

    public function assertRestoreConfirmation(string $confirmation): void
    {
        RehearsalContract::require($confirmation === "RESTORE PRODUCTION " . self::digest($this->packet), "explicit_production_restore_required");
    }
}
