<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Migration\Runner\DeterministicJson;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Infrastructure\Migration\WpdbRehearsalProductVerifier;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class RehearsalProductFingerprintTest extends TestCase
{
    public function testNumericAndNonNumericIdentifiersRemainStringValues(): void
    {
        $input = $this->input(["work" => ["work-a"], "canonical_isbn" => ["123", "00123", "9780306406157"]]);
        self::assertSame([
            ["target_type" => "canonical_isbn", "target_id" => "00123"],
            ["target_type" => "canonical_isbn", "target_id" => "123"],
            ["target_type" => "canonical_isbn", "target_id" => "9780306406157"],
            ["target_type" => "work", "target_id" => "work-a"],
        ], $input);
        $canonical = json_decode(DeterministicJson::encode($input), true, 512, JSON_THROW_ON_ERROR);
        foreach ($canonical as $identity) {
            self::assertIsString($identity["target_id"]);
        }
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', DeterministicJson::hash($input));
    }

    public function testDifferentIdsAndTypesHaveDifferentFingerprints(): void
    {
        $base = DeterministicJson::hash($this->input(["work" => ["123"]]));
        foreach ([["work" => ["124"]], ["work" => ["00123"]], ["edition" => ["123"]]] as $different) {
            self::assertNotSame($base, DeterministicJson::hash($this->input($different)));
        }
    }

    public function testDuplicateMappingsAndSameIdInDifferentTypesAreRetained(): void
    {
        $input = $this->input(["work" => ["123", "123"], "edition" => ["123"]]);
        self::assertCount(3, $input);
        self::assertSame($input[1], $input[2]);
        self::assertNotSame(
            DeterministicJson::hash($this->input(["work" => ["123"], "edition" => ["123"]])),
            DeterministicJson::hash($input)
        );
    }

    public function testFingerprintIsIndependentOfTypeAndMappingOrder(): void
    {
        $first = $this->input(["work" => ["2", "10", "002", "work-a", "2"], "edition" => ["edition-b", "edition-a"]]);
        $second = $this->input(["edition" => ["edition-a", "edition-b"], "work" => ["2", "work-a", "002", "10", "2"]]);
        self::assertSame($first, $second);
        self::assertSame(DeterministicJson::hash($first), DeterministicJson::hash($second));
        self::assertSame(["edition-a", "edition-b", "002", "10", "2", "2", "work-a"], array_column($first, "target_id"));
    }

    public function testIntegerObjectKeysElsewhereAreStillRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Migration object keys must be non-empty UTF-8 strings.");
        DeterministicJson::hash(["canonical_isbn" => [123 => true]]);
    }

    private function input(array $targets): array
    {
        return (new ReflectionMethod(WpdbRehearsalProductVerifier::class, "targetIdentityFingerprintInput"))->invoke(null, $targets);
    }
}
