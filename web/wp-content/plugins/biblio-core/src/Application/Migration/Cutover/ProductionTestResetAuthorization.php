<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

use Biblio\Core\Application\Migration\Runner\DeterministicJson;

/** A bounded test-population reset is never FINAL migration authority. */
final readonly class ProductionTestResetAuthorization
{
    /**
     * @param array<string,mixed> $packet
     * @param array<string,mixed> $approval
     */
    public function __construct(public array $packet, array $approval)
    {
        RehearsalContract::keys($approval, ['purpose', 'packet_digest', 'confirmation']);
        RehearsalContract::require($approval['purpose'] === 'production-test-target-reset', 'test_reset_authorization_required');
        RehearsalContract::equal($approval['packet_digest'], self::digest($packet), 'test_reset_authorization_changed');
        RehearsalContract::equal($approval['confirmation'], 'RESET TEST TARGET ' . self::digest($packet), 'test_reset_authorization_required');
        RehearsalContract::require(($packet['backup']['phase'] ?? null) === 'PRE_RESET', 'pre_reset_backup_required');
    }

    /** @param array<string,mixed> $packet */
    public static function digest(array $packet): string
    {
        return DeterministicJson::hash(['purpose' => 'production-test-target-reset', 'packet' => $packet]);
    }

    public function assertRestoreConfirmation(string $confirmation): void
    {
        RehearsalContract::equal($confirmation, 'RESTORE TEST TARGET ' . self::digest($this->packet), 'explicit_test_reset_restore_required');
    }
}
