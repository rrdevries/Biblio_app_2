<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Reconciliation;

use Biblio\Core\Exception\ValidationException;

final readonly class MigrationMappingContract
{
    /** @var array<string, MigrationMappingRule> */
    private array $rules;

    /** @param list<MigrationMappingRule> $rules */
    public function __construct(private string $sourceType, array $rules)
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $this->sourceType) !== 1) {
            throw new ValidationException("Reconciliation source type is invalid.");
        }
        $indexed = [];
        foreach ($rules as $rule) {
            if (isset($indexed[$rule->targetType()])) {
                throw new ValidationException("Duplicate reconciliation target rule.");
            }
            $indexed[$rule->targetType()] = $rule;
        }
        ksort($indexed, SORT_STRING);
        $this->rules = $indexed;
    }

    public function sourceType(): string { return $this->sourceType; }
    public function rule(string $targetType): ?MigrationMappingRule
    {
        return $this->rules[$targetType] ?? null;
    }
    /** @return list<MigrationMappingRule> */
    public function rules(): array { return array_values($this->rules); }
}
