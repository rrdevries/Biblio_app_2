<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

/**
 * Privacy-safe auxiliary CURRENT classification evidence. Detailed queue
 * contexts remain only in the immutable source package.
 */
final readonly class CurrentV1ClassificationEvidence
{
    /**
     * @param list<array{dimension:string,raw_value:string,status:string}> $definitions
     * @param array{path:string,sha256:string,count:int,status_counts:array<string,int>,source_counts:array<string,int>} $reviewQueue
     * @param array{path:string,sha256:string,count:int,version:int,ids:list<string>} $aliasRules
     */
    public function __construct(
        private array $definitions,
        private array $reviewQueue,
        private array $aliasRules
    ) {
    }

    /** @return list<array{dimension:string,raw_value:string,status:string}> */
    public function definitions(): array { return $this->definitions; }

    /** @return array{path:string,sha256:string,count:int,status_counts:array<string,int>,source_counts:array<string,int>} */
    public function reviewQueue(): array { return $this->reviewQueue; }

    /** @return array{path:string,sha256:string,count:int,version:int,ids:list<string>} */
    public function aliasRules(): array { return $this->aliasRules; }
}
