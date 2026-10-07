<?php

declare(strict_types=1);
namespace Biblio\Core\Application\Metadata\GlobalSearch;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Library\LibraryContextQueryService;
use Biblio\Core\Application\Catalog\{LocalEditionResolver,LocalEditionResolutionType};
use Biblio\Core\Application\Metadata\{MetadataClock,MetadataLookupId,MetadataCandidateId};
use Biblio\Core\Application\Metadata\Discovery\{BibliographicDiscoveryService,BibliographicDiscoveryCandidateReader,BibliographicProviderIdentityRepository,BibliographicCandidateType};
use Biblio\Core\Application\Metadata\Search\{BibliographicTextSearchService,BibliographicTextSearchContract,BibliographicAuthorWorkSearchService,BibliographicAuthorWorkSearchContract,BibliographicAuthorSelectorCodec,BibliographicEditionSearchService,BibliographicEditionSearchContract,BibliographicWorkSelectorCodec};
use Biblio\Core\Catalog\IsbnCanonicalizer;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Library\{LibraryId,LibraryStatus};
use Throwable;

/** Read-only standalone search façade. Existing search consumers keep their contracts. */
final readonly class GlobalBookSearchService
{
    public function __construct(
        private AuthenticatedUser $actor, private MetadataClock $clock,
        private BookSearchTokenCodec $tokens, private IsbnCanonicalizer $isbns,
        private BibliographicTextSearchService $text, private BibliographicTextSearchContract $textContract,
        private BibliographicAuthorWorkSearchService $authorWorks, private BibliographicAuthorWorkSearchContract $authorContract,
        private BibliographicAuthorSelectorCodec $authorSelectors,
        private BibliographicEditionSearchService $editions, private BibliographicEditionSearchContract $editionContract,
        private BibliographicWorkSelectorCodec $workSelectors,
        private BibliographicDiscoveryService $isbnSearch, private BibliographicDiscoveryCandidateReader $candidates,
        private LocalEditionResolver $localEditions, private BibliographicProviderIdentityRepository $mappings,
        private LibraryContextQueryService $libraries, private BookSearchReadRepository $reads,
        private BookSearchDescriptionReader $descriptions, private BookSearchWorkGroupService $works
    ) {}

    public function search(array $p): array
    {
        $this->allowed($p, ['query','author_cursor','work_cursor','search_context','result_group']);
        $query = $this->string($p, 'query');
        $parsed = $this->isbns->parse($query);
        $isbn = $parsed->isValid() ? $parsed->identity() : null;
        $normalized = $isbn?->isbn13()->value() ?? (new \Biblio\Core\Application\Metadata\Search\BibliographicTextSearchQuery($query))->value();
        $context = isset($p['search_context']) ? $this->tokens->decode('context', $p['search_context'])['context'] : $this->tokens->context($normalized);
        if ($context['query'] !== $normalized || ((isset($p['author_cursor']) || isset($p['work_cursor'])) && !isset($p['search_context']))) {
            throw new ValidationException('Search cursor requires its original search context.');
        }
        $group = $p['result_group'] ?? 'all';
        if (!in_array($group,['all','authors','works'],true) || (array_key_exists('result_group',$p) && ($group === 'all' || !isset($p['search_context'])))) {
            throw new ValidationException('Invalid requested result group.');
        }
        // Legacy Work cursors fail as expired context; they are never converted into source offsets.
        $workProgress = null;
        if (isset($p['work_cursor'])) {
            $workProgress = BookSearchWorkProgress::fromData($this->tokens->decode('work_progress',$p['work_cursor'],$context)['data'], new \Biblio\Core\Application\Metadata\Search\BibliographicTextSearchQuery($normalized));
        }
        if ((isset($p['author_cursor']) && $group !== 'authors') || (isset($p['work_cursor']) && $group !== 'works')) {
            throw new ValidationException('Cursor requires its own result group.');
        }
        $envelope = ['version'=>2, 'requested_group'=>$group, 'query'=>['type'=>$isbn === null ? 'text':'isbn','normalized'=>$normalized],
            'search_context'=>$this->tokens->encode('context', [], $context),
            'expires_at'=>gmdate(DATE_ATOM, $context['expires']), 'state'=>'results', 'text_results'=>null, 'isbn_results'=>null];
        if ($isbn !== null) {
            if ($group !== 'all' || isset($p['author_cursor']) || isset($p['work_cursor'])) { throw new ValidationException('ISBN results do not accept text groups or cursors.'); }
            $result = $this->isbnSearch->discover($query);
            $items = [];
            foreach ($result->candidates() as $candidate) {
                $data = $candidate->editionId() === null ? [
                    'result_id'=>'search-edition-'.$candidate->id(), 'edition_id'=>null,
                    'title'=>$candidate->title(), 'subtitle'=>$candidate->subtitle(),
                    'contributors'=>$candidate->contributors(), 'languages'=>$candidate->languages(),
                    'publishers'=>$candidate->publishers(), 'publication_date'=>$candidate->publicationDate(),
                    'isbn_10'=>$candidate->isbn()?->isbn10()?->value(), 'isbn_13'=>$candidate->isbn()?->isbn13()->value(),
                    'format'=>$candidate->format(), 'page_count'=>$candidate->pageCount(),
                    'provider_identity'=>['provider_key'=>$candidate->providerKey(),'record_id'=>$candidate->providerRecordId()],
                    'provider_work_identity'=>$candidate->providerWorkId() === null ? null : ['provider_key'=>$candidate->providerKey(),'record_id'=>$candidate->providerWorkId()],
                    'work_id'=>null,
                ] : ($this->reads->edition($candidate->editionId()->value()) ?? throw new ValidationException('Selected Edition no longer exists.'));
                $proof = $result->discoveryId() === null ? [] : ['discovery_id'=>$result->discoveryId()->value(),'candidate_id'=>$candidate->id()];
                $items[] = $this->wrap($data, 'edition', $context, $proof);
            }
            $envelope['isbn_results'] = ['items'=>$items,'provider_attempts'=>$result->attempts()];
            $envelope['state'] = $result->status()->value === 'provider_failure' ? 'failure' : ($items === [] ? 'no_results':'results');
        } else {
            $data = ['query'=>$normalized, 'authors'=>null, 'works'=>null];
            if ($group !== 'works') {
                $request = $this->textContract->decodeRequest(array_intersect_key($p, array_flip(['query','author_cursor'])));
                [$page,$attempts] = $this->text->searchAuthorsGroup($request);
                $data['authors'] = $this->textContract->serializeAuthorGroup($page,$attempts);
                foreach ($data['authors']['items'] as &$item) { $item = $this->wrap($item,'author',$context); } unset($item);
            }
            if ($group !== 'authors') {
                $page = $this->works->search(new \Biblio\Core\Application\Metadata\Search\BibliographicTextSearchQuery($normalized), $workProgress);
                $items=[];
                foreach ($page->items as $work) {
                    $items[] = $this->wrap($this->textContract->serializeWork($work) + ['presentation_order'=>$work->presentationOrder()], 'work', $context);
                }
                $data['works'] = ['items'=>$items,
                    'next_cursor'=>$page->next === null ? null : $this->tokens->encode('work_progress',$page->next->data(),$context),
                    'provider_attempts'=>$this->textContract->serializeAttempts($page->attempts),
                    'source_state'=>$page->sourceState,
                    'retry_cursor'=>$page->retry === null ? null : $this->tokens->encode('work_progress',$page->retry->data(),$context)];
            }
            $envelope['text_results'] = $data;
            $failed = ($data['authors'] !== null && $this->failed($data['authors']))
                || ($data['works'] !== null && in_array($data['works']['source_state'],['incomplete','failed'],true));
            $count = count($data['works']['items'] ?? []) + count($data['authors']['items'] ?? []);
            $envelope['state'] = $failed ? ($count ? 'partial_failure':'failure') : ($count ? 'results':'no_results');
        }
        return $envelope;
    }

    public function authorWorks(array $p): array
    {
        $this->allowed($p, ['result_selector','search_context','cursor']);
        $selected = $this->selection($p);
        if ($selected['scope'] !== 'author') { throw new ValidationException('An Author selection is required.'); }
        $reference = $this->authorSelectors->decode($selected['record']['author_selector']);
        $page = $this->authorContract->serialize($this->authorWorks->search($this->authorContract->decodeRequest($reference, array_intersect_key($p,array_flip(['cursor'])))));
        foreach ($page['items'] as &$item) { $item = $this->wrap($item, 'work', $selected['context']); } unset($item);
        return $page;
    }

    public function editions(array $p): array
    {
        $this->allowed($p, ['result_selector','search_context','cursor']);
        $s = $this->selection($p);
        if ($s['scope'] !== 'work') { throw new ValidationException('A Work selection is required.'); }
        $ref = $this->workSelectors->decode($s['record']['work_selector']);
        $page = $this->editionContract->serialize($this->editions->search($this->editionContract->decodeRequest($ref, array_intersect_key($p,array_flip(['cursor'])))));
        foreach ($page['items'] as &$item) {
            $item['work_id'] = $ref->workId()?->value();
            $item = $this->wrap($item, 'edition', $s['context'], ['parent_selector'=>$p['result_selector']]);
        } unset($item);
        return $page;
    }

    public function details(array $p): array
    {
        $this->allowed($p, ['result_selector','search_context']);
        $s = $this->selection($p);
        $identity = $this->identity($s);
        $record = $s['record'];
        $book = null; $edition = null;
        if ($s['scope'] === 'work') {
            $book = $identity === null ? $record : ($this->reads->work($identity['id']) ?? throw new ValidationException('Work no longer exists.'));
        } elseif ($s['scope'] === 'edition') {
            $edition = $identity === null ? $record : ($this->reads->edition($identity['id']) ?? throw new ValidationException('Edition no longer exists.'));
            if (is_string($edition['work_id']??null)) { $book = $this->reads->work($edition['work_id']); }
            elseif (isset($s['proof']['parent_selector'])) {
                $parent = $this->tokens->decode('selection', $s['proof']['parent_selector'], $s['context'])['data'];
                $this->identity($parent + ['context'=>$s['context']]);
                $book = $parent['record'];
            }
        } else { throw new ValidationException('A Work or Edition selection is required.'); }
        return ['result_id'=>$record['result_id'],'entity_type'=>$s['scope'],'book'=>$book,'edition'=>$edition];
    }

    public function presence(array $p): array
    {
        $this->allowed($p, ['result_selectors','search_context']);
        $ctx = $this->tokens->decode('context', $p['search_context']??null)['context'];
        $selectors = $p['result_selectors']??null;
        if (!is_array($selectors) || !array_is_list($selectors) || count($selectors)<1 || count($selectors)>50) { throw new ValidationException('Presence requires 1 to 50 selections.'); }
        $items = []; $identities = [];
        foreach ($selectors as $token) {
            $s = $this->tokens->decode('selection', $token, $ctx)['data'] + ['context'=>$ctx];
            if (!in_array($s['scope'], ['work','edition'], true)) { throw new ValidationException('Invalid presence scope.'); }
            $id = $s['record']['result_id'];
            $identityFailed = false;
            try { $identity = $this->identity($s); }
            catch (Throwable) { $identity = null; $identityFailed = true; }
            $items[$id] = ['result_id'=>$id,'identity_scope'=>$s['scope'],'state'=>$identityFailed ? 'failure' : ($identity === null ? 'unknown':'no_confirmed')];
            if ($identity !== null) { $identities[$id] = $identity; }
        }
        try {
            $present = $this->reads->presence($identities, array_keys($this->accessible()));
            foreach ($present as $id=>$value) { if (isset($items[$id]) && $value) { $items[$id]['state']='present'; } }
        } catch (Throwable) { foreach ($items as &$item) { $item['state']='failure'; } unset($item); }
        return ['items'=>array_values($items)];
    }

    public function catalogs(array $p): array
    {
        $this->allowed($p, ['result_selector','search_context','cursor']);
        $s = $this->selection($p);
        try { $identity = $this->identity($s); }
        catch (ValidationException $exception) {
            if (isset($p['cursor'])) { throw new BookSearchAccessChanged('Selected catalog identity changed. Reload the first page.'); }
            throw $exception;
        }
        if ($identity === null) {
            if (isset($p['cursor'])) { throw new BookSearchAccessChanged('Selected catalog identity is no longer confirmed.'); }
            return ['identity_scope'=>$s['scope'],'state'=>'unknown','items'=>[],'next_cursor'=>null,'access_scope'=>null];
        }
        $allowed = $this->accessible();
        $libraryIds = array_keys($allowed); sort($libraryIds, SORT_STRING);
        $accessScope = hash('sha256', json_encode($libraryIds, JSON_THROW_ON_ERROR));
        $after = null;
        if (isset($p['cursor'])) {
            $cursor = $this->tokens->decode('catalog_cursor', $p['cursor'], $s['context'])['data'];
            if ($cursor['result_id'] !== $s['record']['result_id']) { throw new ValidationException('Catalog cursor belongs to another selection.'); }
            if ($cursor['identity'] !== $identity) { throw new BookSearchAccessChanged('Selected catalog identity changed. Reload the first page.'); }
            if (($cursor['access_scope']??null) !== $accessScope) { throw new BookSearchAccessChanged('Catalog access changed. Reload the first page.'); }
            $after = $cursor['after'];
        }
        $rows = $this->reads->registrations($identity['scope'],$identity['id'],array_keys($allowed),$after,26);
        $more = count($rows)>25;
        if ($more) { $rows = array_slice($rows,0,25); }
        $items = [];
        foreach ($rows as $row) {
            // Revalidate access for each projected group; never trust a client Library ID.
            $library = $this->libraries->get(new LibraryId($row['library_id']));
            if ($library->status() !== LibraryStatus::Active || !isset($allowed[$row['library_id']])) { continue; }
            $items[] = $row + ['library_name'=>$library->name()->value(),'edition'=>$this->reads->edition($row['edition_id'])];
        }
        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        $cursor = $more && $last !== null ? $this->tokens->encode('catalog_cursor', ['result_id'=>$s['record']['result_id'],'identity'=>$identity,'access_scope'=>$accessScope,'after'=>[$last['library_id'],$last['item_id']]],$s['context']) : null;
        return ['identity_scope'=>$s['scope'],'state'=>$items === [] ? 'no_confirmed':'present','items'=>$items,'next_cursor'=>$cursor,'access_scope'=>$this->tokens->encode('catalog_access',['fingerprint'=>$accessScope],$s['context'])];
    }

    public function description(array $p): array
    {
        $this->allowed($p, ['result_selector','search_context']);
        $s = $this->selection($p);
        $identity = $this->identity($s);
        $source = $identity === null ? null : $this->reads->descriptionSource($identity['scope'],$identity['id']);
        if ($source === null) {
            if ($s['scope']==='work') {
                $ref = $this->workSelectors->decode($s['record']['work_selector']);
                $provider = $ref->providerIdentity();
                if ($provider !== null) { $source = ['provider_key'=>$provider->providerKey(),'record_id'=>$provider->providerRecordId(),'scope'=>'work']; }
            } elseif (isset($s['record']['provider_identity'])) { $source = $s['record']['provider_identity'] + ['scope'=>'edition']; }
        }
        return $this->descriptions->read($source);
    }

    public function validate(array $p): array
    {
        $this->allowed($p,['search_context']);
        $ctx = $this->tokens->decode('context',$p['search_context']??null)['context'];
        return ['valid'=>true,'context_scope'=>$ctx['id'],'expires_at'=>gmdate(DATE_ATOM,$ctx['expires'])];
    }

    private function wrap(array $record, string $scope, array $context, array $proof=[]): array
    {
        // Consumer-write capabilities are deliberately absent from the new read contract.
        unset($record['requires_materialization'],$record['can_add_work_only'],$record['can_add_edition_specific']);
        $record['result_selector'] = $this->tokens->encode('selection',['scope'=>$scope,'record'=>$record,'proof'=>$proof],$context);
        return $record;
    }
    private function selection(array $p): array
    {
        $ctx = $this->tokens->decode('context',$p['search_context']??null)['context'];
        return $this->tokens->decode('selection',$p['result_selector']??null,$ctx)['data'] + ['context'=>$ctx];
    }
    private function identity(array $s): ?array
    {
        $r = $s['record'];
        if ($s['scope']==='work') {
            $ref = $this->workSelectors->decode($r['work_selector']);
            $id = $ref->workId();
            if ($id === null && ($provider=$ref->providerIdentity()) !== null) { $id=$this->mappings->findWork($provider->providerKey(),'work',$provider->providerRecordId()); }
            return $id === null ? null : ['scope'=>'work','id'=>$id->value()];
        }
        if ($s['scope']!=='edition') { return null; }
        if (isset($s['proof']['discovery_id'])) {
            $candidate=$this->candidates->candidateForRead(new MetadataLookupId($s['proof']['discovery_id']),new MetadataCandidateId($s['proof']['candidate_id']),$this->actor->requireUserId(),$this->clock->now());
            if ($candidate === null || $candidate->type()!==BibliographicCandidateType::ExternalEdition) { throw new ValidationException('Edition candidate is invalid or expired.'); }
        }
        $id = $r['edition_id']??null;
        $mapped = isset($r['provider_identity']) ? $this->mappings->findEdition($r['provider_identity']['provider_key'],$r['provider_identity']['record_id'])?->value() : null;
        if ($id !== null && $mapped !== null && $id !== $mapped) { throw new ValidationException('Edition mapping changed.'); }
        $id ??= $mapped;
        if (is_string($r['isbn_13']??null)) {
            $resolution = $this->localEditions->resolveInput($r['isbn_13']);
            if ($resolution->type()===LocalEditionResolutionType::LocalAmbiguous && $id === null) { return null; }
            if ($resolution->type()===LocalEditionResolutionType::LocalExact) {
                $isbnId=$resolution->requireEdition()->id()->value();
                if ($id !== null && $isbnId!==$id) { throw new ValidationException('Provider Edition and ISBN identity disagree.'); }
                $id ??= $isbnId;
            }
        }
        return $id === null ? null : ['scope'=>'edition','id'=>$id];
    }
    private function accessible(): array
    {
        $this->actor->requireUserId(); $allowed=[];
        foreach ($this->libraries->myLibraries() as $l) { if ($l->status()===LibraryStatus::Active) { $allowed[$l->libraryId()->value()]=$l; } }
        return $allowed;
    }
    private function allowed(array $p, array $fields): void
    {
        $this->actor->requireUserId();
        if (array_diff(array_keys($p),$fields)!==[]) { throw new ValidationException('Unknown search request field.'); }
    }
    private function string(array $p, string $key): string
    { if (!is_string($p[$key]??null)) { throw new ValidationException('Missing search query.'); } return $p[$key]; }
    private function failed(array $group): bool
    { foreach ($group['provider_attempts'] as $a) { if ($a['failure_reason'] !== null) { return true; } } return false; }
}
