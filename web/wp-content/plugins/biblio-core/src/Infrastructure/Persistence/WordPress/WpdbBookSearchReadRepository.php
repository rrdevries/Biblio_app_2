<?php

declare(strict_types=1);
namespace Biblio\Core\Infrastructure\Persistence\WordPress;
use Biblio\Core\Application\Metadata\GlobalSearch\BookSearchReadRepository;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use wpdb;

final readonly class WpdbBookSearchReadRepository implements BookSearchReadRepository
{
    public function __construct(private wpdb $db, private CoreTableNames $tables) {}
    public function work(string $id): ?array
    {
        $row=$this->db->get_row($this->db->prepare("SELECT work_id,work_title FROM `{$this->tables->works()}` WHERE work_id=%s",$id)); $this->check();
        if ($row===null) { return null; }
        $authors=$this->db->get_results($this->db->prepare("SELECT a.author_id,a.display_name FROM `{$this->tables->workContributors()}` wc INNER JOIN `{$this->tables->authors()}` a ON a.author_id=wc.author_id WHERE wc.work_id=%s ORDER BY wc.contributor_position,a.author_id",$id),ARRAY_A); $this->check();
        $series=$this->db->get_results($this->db->prepare("SELECT s.series_id,s.display_name,ws.series_position AS position FROM `{$this->tables->workSeries()}` ws INNER JOIN `{$this->tables->series()}` s ON s.series_id=ws.series_id WHERE ws.work_id=%s ORDER BY s.display_name,s.series_id",$id),ARRAY_A); $this->check();
        return ['result_id'=>'search-work-'.hash('sha256',"work\0".$id),'work_id'=>$id,'title'=>(string)$row->work_title,'authors'=>$authors,'series'=>$series];
    }
    public function edition(string $id): ?array
    {
        $row=$this->db->get_row($this->db->prepare("SELECT edition_id,work_id,edition_title,isbn_10,isbn_13 FROM `{$this->tables->editions()}` WHERE edition_id=%s",$id)); $this->check();
        if ($row===null) { return null; }
        $work=$this->work((string)$row->work_id);
        return ['result_id'=>'search-edition-'.hash('sha256',"edition\0canonical\0".$id),'edition_id'=>$id,'work_id'=>(string)$row->work_id,'title'=>(string)$row->edition_title,'subtitle'=>null,'contributors'=>array_column($work['authors']??[],'display_name'),'languages'=>[],'publishers'=>[],'publication_date'=>null,'isbn_10'=>$row->isbn_10,'isbn_13'=>$row->isbn_13,'format'=>null,'page_count'=>null,'provider_identity'=>null,'provider_work_identity'=>null];
    }
    public function registrations(string $scope, string $id, array $libraryIds, ?array $after, int $limit): array
    {
        if ($libraryIds===[]) { return []; }
        if (!in_array($scope,['work','edition'],true) || $limit<1 || $limit>26) { throw new \InvalidArgumentException('Invalid search read.'); }
        $params=[...$libraryIds,$id];
        $where="i.library_id IN (".$this->placeholders(count($libraryIds)).") AND i.item_status='active' AND ".($scope==='work'?'e.work_id':'e.edition_id')."=%s";
        if ($after!==null) { $where.=' AND (i.library_id>%s OR (i.library_id=%s AND i.item_id>%s))'; array_push($params,$after[0],$after[0],$after[1]); }
        $params[]=$limit;
        $rows=$this->db->get_results($this->db->prepare("SELECT i.library_id,i.item_id,i.edition_id FROM `{$this->tables->items()}` i INNER JOIN `{$this->tables->editions()}` e ON e.edition_id=i.edition_id WHERE {$where} ORDER BY i.library_id,i.item_id LIMIT %d",...$params),ARRAY_A); $this->check();
        return $rows;
    }
    public function presence(array $identities, array $libraryIds): array
    {
        if ($identities===[] || $libraryIds===[]) { return []; }
        $predicates=[]; $params=$libraryIds;
        foreach ($identities as $identity) { $predicates[] = ($identity['scope']==='work'?'e.work_id':'e.edition_id').'=%s'; $params[]=$identity['id']; }
        // No per-result Catalogus read; one bounded batch, no personal reading joins.
        $rows=$this->db->get_results($this->db->prepare("SELECT DISTINCT e.work_id,e.edition_id FROM `{$this->tables->items()}` i INNER JOIN `{$this->tables->editions()}` e ON e.edition_id=i.edition_id WHERE i.library_id IN (".$this->placeholders(count($libraryIds)).") AND i.item_status='active' AND (".implode(' OR ',$predicates).")",...$params),ARRAY_A); $this->check();
        $found=['work'=>[],'edition'=>[]]; foreach ($rows as $r) { $found['work'][$r['work_id']]=true; $found['edition'][$r['edition_id']]=true; }
        $result=[]; foreach ($identities as $key=>$identity) { $result[$key]=isset($found[$identity['scope']][$identity['id']]); } return $result;
    }
    public function descriptionSource(string $scope, string $id): ?array
    {
        if (!in_array($scope,['work','edition'],true)) { return null; }
        $column=$scope==='work'?'work_id':'edition_id';
        $row=$this->db->get_row($this->db->prepare("SELECT provider_key,provider_record_id FROM `{$this->tables->bibliographicProviderIdentities()}` WHERE target_type=%s AND source_entity_type=%s AND {$column}=%s AND (provider_key='open_library' OR provider_key='google_books') ORDER BY CASE WHEN provider_key='open_library' THEN 0 ELSE 1 END,provider_record_id LIMIT 1",$scope,$scope,$id)); $this->check();
        return $row===null ? null : ['provider_key'=>(string)$row->provider_key,'record_id'=>(string)$row->provider_record_id,'scope'=>$scope];
    }
    private function placeholders(int $n): string { return implode(',',array_fill(0,$n,'%s')); }
    private function check(): void { if ($this->db->last_error!=='') { throw new PersistenceException('Bibliographic search read failed.',failureReason:FailureReason::PersistenceReadFailed); } }
}
