<?php

declare(strict_types=1);
namespace Biblio\Core\Application\Metadata\GlobalSearch;
use Biblio\Core\Application\Metadata\Search\{BibliographicWorkSearchResult,BibliographicSearchProviderAttempt};
use InvalidArgumentException;

final readonly class BookSearchWorkGroup
{
    /** @param list<BibliographicWorkSearchResult> $items @param list<BibliographicSearchProviderAttempt> $attempts */
    public function __construct(public array $items, public ?BookSearchWorkProgress $next,
        public array $attempts, public string $sourceState, public ?BookSearchWorkProgress $retry = null)
    {
        if (!in_array($sourceState,['complete','incomplete','failed','not_checked'],true)
            || (in_array($sourceState,['incomplete','failed'],true) !== ($retry !== null))
            || ($retry !== null && $retry->action !== 'retry') || ($next !== null && $next->action !== 'continue')
            || !array_is_list($items) || count($items) > 10) { throw new InvalidArgumentException('Invalid standalone Work group.'); }
        $seen=[]; $last=null;
        foreach ($items as $item) {
            if (!$item instanceof BibliographicWorkSearchResult || isset($seen[$item->reference()->resultId()])
                || ($last !== null && $last >= $item->sortKey())) { throw new InvalidArgumentException('Invalid standalone Work result order.'); }
            $seen[$item->reference()->resultId()] = true; $last=$item->sortKey();
        }
        foreach ($attempts as $attempt) { if (!$attempt instanceof BibliographicSearchProviderAttempt) { throw new InvalidArgumentException('Invalid source attempt.'); } }
    }
}
