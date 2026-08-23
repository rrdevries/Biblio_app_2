<?php
declare(strict_types=1);namespace Biblio\Core\Application\Assessments;use Biblio\Core\Assessments\{RatingId,RatingVersion};final readonly class DeleteOwnRatingService{public function __construct(private SourceContributionService $source){}public function delete(RatingId $id,RatingVersion $version):void{$this->source->deleteRating($id,$version);}}
