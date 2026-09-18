<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Runner\{
    DeterministicJson,
    MigrationRunnerFailure,
    MigrationRunnerReason,
    MigrationSourceInspection,
    MigrationSourceRecord
};
use Biblio\Core\Exception\ValidationException;

/** Exact manifest- and payload-bound D-MIG-COPY-EXCL-01 decision. */
final readonly class CurrentV1ReviewedCopyExclusionContract
{
    public const VERSION = "d-mig-copy-excl-01.2026-09-18";
    public const MANIFEST_SHA256 =
        "35a18156490f103d4b6b610f524a1963e5be189d74c64637c49059396b1c7c67";
    public const SET_SHA256 =
        "c1d54e96fd3330fa329a397fcc9f69c6556e6d4cd299c153be9c059145c9353e";

    /** @var list<array{source_id:string,payload_hash:string}> */
    private const REVIEWED_SET = [
        ["source_id" => "copy-1770132685978-extra-h9lics", "payload_hash" => "05676a8d4273e75574b2645f8c6303454990f6c89f602b47fe5b5534eb53f5f5"],
        ["source_id" => "copy-1770933193144-01-y1epi2", "payload_hash" => "b564deaf0732e635e2ccbe635318a58e805fbc176195696d6af78dc8a919a63b"],
        ["source_id" => "copy-1770933209550-01-50pe80", "payload_hash" => "da9d3ad6542001f906bdea5f772d45c51673771b42a9da70f0ce72209c4516ab"],
        ["source_id" => "copy-1770933500943-01-u4wrne", "payload_hash" => "7cf034006b66180fd00d6463d744f1efde8dcc5b0c95b61fb28a0470e8fafdf6"],
        ["source_id" => "copy-1770933964782-01-jkm4gc", "payload_hash" => "168e5787710651baf3173729513f711fea17a068d4353820ae5c76674bb6e69d"],
        ["source_id" => "copy-1770934366206-01-9rcfl8", "payload_hash" => "4a2905df71782875eba3d551b95c743fc803b4b85dfe240706ad66b25128b9b9"],
        ["source_id" => "copy-1770934417006-01-wx4ykf", "payload_hash" => "c1812b4aed6293ce927f6d121cd287954edaf07b383c7702e7fabbaeeeb7a6ef"],
        ["source_id" => "copy-1770934446089-01-utrjhc", "payload_hash" => "47bee0598e540b050cfd9bf77f551706f0234ef0a5af86fae2bea59e397d438f"],
        ["source_id" => "copy-1770934733290-01-acj7mu", "payload_hash" => "db16f60bd6c643473a259a67958f14148d743a7fec2b1f5b26fd46734579de2c"],
        ["source_id" => "copy-1770934745810-01-f2dayx", "payload_hash" => "1d9da39a2b72aa4a0afb4825b3bc6507e71c891872c4da593176e8712c57eef2"],
        ["source_id" => "copy-1770934847989-01-e2v7cu", "payload_hash" => "f5fcc33b3f9419613904b4635ba9aa9584a17175f99303706bb856e3fbf2a80e"],
        ["source_id" => "copy-1770934920693-01-ewvrgd", "payload_hash" => "b1a73898b7183134a1edada71e2f093624e82e1eee9b38fac8ad0d6a0bc2c041"],
        ["source_id" => "copy-1770934958001-01-fo8t1q", "payload_hash" => "0e99089f8a65fbc1507e427f7d1061587f3008dc2d2f5e44a7f1157ee6393ade"],
        ["source_id" => "copy-1770935647569-01-es56ih", "payload_hash" => "f06cb70edd92839b2b207d52682569ce54407255e8526dbd4929aa9a4fe40504"],
        ["source_id" => "copy-1770936121118-01-saygms", "payload_hash" => "3eff56d10f931221362822e3b606bbc98b8a05280d7baddee96b650c8c174b06"],
        ["source_id" => "copy-1771063725794-01-ljtxtd", "payload_hash" => "f9f81cd0daca965874dcfe0d3f6e75e2b4869600e25b670788f997468cec2965"],
        ["source_id" => "copy-1771063770682-01-e50za4", "payload_hash" => "d519d66d3a307b49deae2fb8cb58ad7eb85a460d00bfceff355d589e1cdbbd32"],
        ["source_id" => "copy-1771064428453-01-ne32dd", "payload_hash" => "62cebffb24aca125ce4fcaa6535f871471ddb3e7b19ae4d5f127953ba75a0a98"],
        ["source_id" => "copy-1771265560621-extra-kzph0b", "payload_hash" => "546a3c5b1a5e774517253d5a8fe58858bcdf15ee842373d2290352ee9e4d0e77"],
        ["source_id" => "copy-1771336523170-01-bfn8p5", "payload_hash" => "b2d62bc6697f546c4642bdde204debbe22994a39c415dea619db11246705664b"],
        ["source_id" => "copy-1786129044856-01-pfdtbk", "payload_hash" => "51e96bbcde79355ac05a9209e54d040fc216a7c79878676202a2f1f93466d02f"],
        ["source_id" => "copy-1786710970785-01-lr6pja", "payload_hash" => "8cc155fc8ea94de9ae7d5bd0074ad29d3d4fd3ec0ef92f564afac0d30d22aff8"],
        ["source_id" => "copy-1787945777590-01-ebymmj", "payload_hash" => "444117d1df925402d0b73fa6a8602292f70c62bf65cbcc6377590530dd92b026"],
    ];

    /** @var array<string,string> */
    private array $hashesById;
    private string $setSha256;

    /** @param list<array<string,mixed>>|null $reviewedSet */
    public function __construct(
        private string $manifestSha256 = self::MANIFEST_SHA256,
        ?array $reviewedSet = null
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $this->manifestSha256) !== 1) {
            throw new ValidationException("Copy exclusion manifest digest is invalid.");
        }
        $input = $reviewedSet ?? self::REVIEWED_SET;
        $set = [];
        $indexed = [];
        foreach ($input as $entry) {
            $sourceId = $entry["source_id"] ?? null;
            $payloadHash = $entry["payload_hash"] ?? null;
            if (
                !is_string($sourceId)
                || trim($sourceId) === ""
                || !is_string($payloadHash)
                || preg_match('/^[a-f0-9]{64}$/D', $payloadHash) !== 1
                || isset($indexed[$sourceId])
            ) {
                throw new ValidationException("Reviewed Copy exclusion set is invalid.");
            }
            $indexed[$sourceId] = $payloadHash;
            $set[] = ["source_id" => $sourceId, "payload_hash" => $payloadHash];
        }
        usort($set, static fn (array $a, array $b): int =>
            $a["source_id"] <=> $b["source_id"]);
        $this->hashesById = $indexed;
        $this->setSha256 = DeterministicJson::hash($set);
        if ($reviewedSet === null && !hash_equals(self::SET_SHA256, $this->setSha256)) {
            throw new ValidationException("Reviewed Copy exclusion set digest is invalid.");
        }
    }

    /** @param array<string,MigrationSourceRecord> $copiesByKey */
    public function assertMatches(
        MigrationSourceInspection $inspection,
        array $copiesByKey
    ): void {
        if (!hash_equals($this->manifestSha256, $inspection->package()->manifestDigest())) {
            throw $this->changed();
        }
        $observed = [];
        foreach ($copiesByKey as $copy) {
            $expected = $this->hashesById[$copy->sourceId()] ?? null;
            if ($expected !== null) {
                if (!hash_equals($expected, $copy->payloadHash())) {
                    throw $this->changed();
                }
                $observed[$copy->sourceId()] = $copy->payloadHash();
            }
            if ($this->hasReviewedCorrectionShape($copy->payload())) {
                if ($expected === null) {
                    throw $this->changed();
                }
            }
        }
        ksort($observed, SORT_STRING);
        if ($observed !== $this->hashesById) {
            throw $this->changed();
        }
    }

    public function excludes(MigrationSourceRecord $copy): bool
    {
        $expected = $this->hashesById[$copy->sourceId()] ?? null;
        return $expected !== null && hash_equals($expected, $copy->payloadHash());
    }

    public function identity(): string
    {
        return self::VERSION . ":set:" . substr($this->setSha256, 0, 24);
    }

    public function setSha256(): string { return $this->setSha256; }
    public function count(): int { return count($this->hashesById); }

    /** @param array<string,mixed> $payload */
    private function hasReviewedCorrectionShape(array $payload): bool
    {
        return ($payload["archived"] ?? null) === true
            && is_string($payload["archivedAt"] ?? null)
            && $payload["archivedAt"] !== ""
            && ($payload["status"] ?? null) === "disposed"
            && ($payload["ownershipStatus"] ?? null) === "none"
            && in_array(
                $payload["archiveReason"] ?? null,
                ["duplicate_correction", "incorrectly_registered", "wishlist_correction"],
                true
            );
    }

    private function changed(): MigrationRunnerFailure
    {
        return new MigrationRunnerFailure(
            MigrationRunnerReason::SourceChanged,
            "The reviewed CURRENT Copy exclusion set does not match the source."
        );
    }
}
