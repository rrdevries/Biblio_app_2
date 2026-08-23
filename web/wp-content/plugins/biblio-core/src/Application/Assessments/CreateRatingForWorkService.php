<?php
declare(strict_types=1);namespace Biblio\Core\Application\Assessments;use Biblio\Core\Assessments\{Rating,RatingValue};use Biblio\Core\Catalog\WorkId;final readonly class CreateRatingForWorkService{public function __construct(private SourceContributionService $source){}public function create(WorkId $work,RatingValue $value):Rating{return $this->source->createRatingForWork($work,$value);}}
