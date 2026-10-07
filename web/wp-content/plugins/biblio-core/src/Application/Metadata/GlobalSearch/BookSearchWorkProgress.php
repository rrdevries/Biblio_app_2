<?php

declare(strict_types=1);
namespace Biblio\Core\Application\Metadata\GlobalSearch;
use Biblio\Core\Application\Metadata\Search\{BibliographicTextSearchQuery,BibliographicSearchCursor,BibliographicSearchGroup,BibliographicSearchResultKind};
use Biblio\Core\Exception\ValidationException;

/** Signed source progress is independent of the last displayed result. */
final readonly class BookSearchWorkProgress
{
    public function __construct(public string $phase, public int $offset = 0, public int $capacity = 10,
        public ?BibliographicSearchCursor $localCursor = null, public string $action = 'continue')
    {
        if (!in_array($phase,['local','external'],true) || !in_array($action,['continue','retry'],true)
            || $offset < 0 || $offset > 1000000 || $capacity < 1 || $capacity > 10
            || ($phase === 'local' && ($action !== 'continue' || $offset !== 0 || $capacity !== 10
                || ($localCursor !== null && ($localCursor->kind() !== BibliographicSearchResultKind::LocalCanonical || $localCursor->group() !== BibliographicSearchGroup::Works))))
            || ($phase === 'external' && $localCursor !== null)) {
            throw new ValidationException('Invalid standalone Work progress.');
        }
    }
    public function data(): array
    {
        return ['v'=>1,'order_contract'=>'local-then-source-v1','group'=>'works','phase'=>$this->phase,
            'offset'=>$this->offset,'capacity'=>$this->capacity,'action'=>$this->action,
            'local_cursor'=>$this->localCursor === null ? null : ['order'=>$this->localCursor->presentationOrder(),'result_id'=>$this->localCursor->resultId()]];
    }
    public static function fromData(array $p, BibliographicTextSearchQuery $query): self
    {
        if (array_keys($p) !== ['v','order_contract','group','phase','offset','capacity','action','local_cursor']
            || $p['v'] !== 1 || $p['order_contract'] !== 'local-then-source-v1' || $p['group'] !== 'works'
            || !is_string($p['phase']) || !is_int($p['offset']) || !is_int($p['capacity']) || !is_string($p['action'])) {
            throw new ValidationException('Invalid standalone Work progress data.');
        }
        $local = null;
        if ($p['local_cursor'] !== null) {
            $c = $p['local_cursor'];
            if (!is_array($c) || array_keys($c) !== ['order','result_id'] || !is_int($c['order']) || !is_string($c['result_id'])) {
                throw new ValidationException('Invalid local progress data.');
            }
            $local = new BibliographicSearchCursor($query, BibliographicSearchGroup::Works, BibliographicSearchResultKind::LocalCanonical, $c['order'], $c['result_id']);
        }
        if (($p['phase'] === 'local' && $local === null)
            || ($p['phase'] === 'external' && $p['action'] === 'continue' && $p['capacity'] !== 10)) {
            throw new ValidationException('Invalid issued progress combination.');
        }
        return new self($p['phase'],$p['offset'],$p['capacity'],$local,$p['action']);
    }
}
