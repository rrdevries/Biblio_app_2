<?php
declare(strict_types=1);
namespace Biblio\Core\Assessments;
interface ReviewIdGenerator { public function next(): ReviewId; }
