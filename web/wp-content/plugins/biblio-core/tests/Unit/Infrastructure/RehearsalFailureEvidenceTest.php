<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Migration\Cutover\{RehearsalFailure, RehearsalFailureEvidence};
use PHPUnit\Framework\TestCase;

final class RehearsalFailureEvidenceTest extends TestCase
{
    public function testPrivateMessageIsHashedAndRedactedWhileCauseChainIsPreserved(): void
    {
        $private = "synthetic-private-Note-body credential=forbidden";
        $root = new \LogicException($private);
        $outer = new \RuntimeException("source-body=" . $private, 0, $root);
        $evidence = RehearsalFailureEvidence::describe($outer, ["completion", "post_apply_product_verification"]);

        self::assertSame("post_apply_product_verification", $evidence["subphase"]);
        self::assertSame(\RuntimeException::class, $evidence["original"]["class"]);
        self::assertSame(\LogicException::class, $evidence["root"]["class"]);
        self::assertCount(2, $evidence["cause_chain"]);
        self::assertSame("[redacted]", $evidence["original"]["sanitized_message"]);
        self::assertSame(hash("sha256", $root->getMessage()), $evidence["root"]["message_sha256"]);
        self::assertStringNotContainsString($private, json_encode($evidence, JSON_THROW_ON_ERROR));
    }

    public function testTopLevelReasonAndPreviousRemainAvailableWithoutChangingPublicMessage(): void
    {
        $original = new \LogicException("synthetic-private-Review-body");
        $wrapped = new RehearsalFailure("apply_or_verification_failed", $original);
        self::assertSame("apply_or_verification_failed", $wrapped->reason);
        self::assertSame("Guarded rehearsal refused: apply_or_verification_failed.", $wrapped->getMessage());
        self::assertSame($original, $wrapped->getPrevious());
    }
}
