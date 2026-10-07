<?php

declare(strict_types=1);
namespace Biblio\Core\Application\Metadata\GlobalSearch;
use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Metadata\Discovery\BibliographicProviderIdentityRepository;
use Biblio\Core\Application\Metadata\ProviderLookupStatus;
use Biblio\Core\Application\Metadata\Search\{BibliographicTextSearchQuery,BibliographicWorkSearchProvider,BibliographicWorkSourceProvider,BibliographicLocalWorkQueryMatcher,BibliographicSearchProviderAttempt,BibliographicSearchProviderFailure};
use Biblio\Core\Exception\ValidationException;

final readonly class BookSearchWorkGroupService
{
    public function __construct(private AuthenticatedUser $actor, private BibliographicWorkSearchProvider $local,
        private BibliographicWorkSourceProvider $source, private BibliographicLocalWorkQueryMatcher $matcher,
        private BibliographicProviderIdentityRepository $mappings) {}

    public function search(BibliographicTextSearchQuery $query, ?BookSearchWorkProgress $progress = null): BookSearchWorkGroup
    {
        $this->actor->requireUserId();
        $progress ??= new BookSearchWorkProgress('local');
        $items=[];
        if ($progress->phase === 'local') {
            if ($progress->localCursor !== null && $progress->localCursor->query()->value() !== $query->value()) {
                throw new ValidationException('Local progress query changed.');
            }
            $local = $this->local->searchWorks($query,$progress->localCursor);
            $items = $local->items();
            if ($local->nextCursor() !== null) {
                return new BookSearchWorkGroup($items,new BookSearchWorkProgress('local',localCursor:$local->nextCursor()),[],'not_checked');
            }
            if (count($items) === 10) {
                return new BookSearchWorkGroup($items,new BookSearchWorkProgress('external'),[],'not_checked');
            }
            $progress = new BookSearchWorkProgress('external',0,10-count($items));
        }
        $retry = new BookSearchWorkProgress('external',$progress->offset,$progress->capacity,action:'retry');
        try { $page = $this->source->searchWorkSource($query,$progress->offset,$progress->capacity); }
        catch (BibliographicSearchProviderFailure $failure) {
            return new BookSearchWorkGroup($items,null,[new BibliographicSearchProviderAttempt($this->source->key(),$failure->status(),$failure->reason())],'failed',$retry);
        }
        if ($page->query()->value() !== $query->value() || $page->requestedOffset() !== $progress->offset
            || $page->requestedCapacity() !== $progress->capacity) { throw new ValidationException('Work source returned another window.'); }
        $seen=[];
        foreach ($page->items() as $item) {
            $identity=$item->reference()->providerIdentity();
            $mapped=$this->mappings->findWork($identity->providerKey(), 'work', $identity->providerRecordId());
            if (($mapped !== null && $this->matcher->workMatchesQuery($mapped,$query)) || isset($seen[$item->reference()->resultId()])) { continue; }
            $seen[$item->reference()->resultId()]=true; $items[]=$item;
        }
        $incomplete=$page->rejectedCount()>0;
        return new BookSearchWorkGroup($items,
            $page->nextOffset() === null ? null : new BookSearchWorkProgress('external',$page->nextOffset()),
            [new BibliographicSearchProviderAttempt($this->source->key(),$page->items() === [] ? ProviderLookupStatus::Miss : ProviderLookupStatus::Candidates)],
            $incomplete ? 'incomplete':'complete',$incomplete ? $retry:null);
    }
}
