<?php
declare(strict_types=1);namespace Biblio\Core\Application\Assessments;use Biblio\Core\Assessments\{ReviewId,ReviewVersion};final readonly class DeleteOwnReviewService{public function __construct(private SourceContributionService $source){}public function delete(ReviewId $id,ReviewVersion $version):void{$this->source->deleteReview($id,$version);}}
