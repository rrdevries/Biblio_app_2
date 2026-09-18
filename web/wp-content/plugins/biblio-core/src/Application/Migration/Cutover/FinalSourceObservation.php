<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

use Biblio\Core\Exception\ValidationException;

final readonly class FinalSourceObservation
{
    public function __construct(
        private string $domain,
        private string $sourceType,
        private string $sourceId,
        private string $payloadHash,
        private string $shapeHash,
        private string $semanticClass,
        private bool $structural = false,
        private string $state = "ordinary"
    ) {
        foreach ([$this->domain, $this->sourceType, $this->sourceId, $this->semanticClass, $this->state] as $value) {
            if (trim($value) === "" || mb_strlen($value) > 191) {
                throw new ValidationException("Final source observation identity is invalid.");
            }
        }
        foreach ([$this->payloadHash, $this->shapeHash] as $hash) {
            if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new ValidationException("Final source observation hash is invalid.");
            }
        }
    }

    public function domain(): string { return $this->domain; }
    public function sourceType(): string { return $this->sourceType; }
    public function sourceId(): string { return $this->sourceId; }
    public function payloadHash(): string { return $this->payloadHash; }
    public function shapeHash(): string { return $this->shapeHash; }
    public function semanticClass(): string { return $this->semanticClass; }
    public function structural(): bool { return $this->structural; }
    public function state(): string { return $this->state; }
    public function key(): string { return $this->domain . "\0" . $this->sourceType . "\0" . $this->sourceId; }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            "domain" => $this->domain,
            "source_type" => $this->sourceType,
            "source_id" => $this->sourceId,
            "payload_hash" => $this->payloadHash,
            "shape_hash" => $this->shapeHash,
            "semantic_class" => $this->semanticClass,
            "structural" => $this->structural,
            "state" => $this->state,
        ];
    }
}
