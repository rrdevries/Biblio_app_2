<?php
declare(strict_types=1);
namespace Biblio\Core\Assessments;
interface RatingIdGenerator { public function next(): RatingId; }
