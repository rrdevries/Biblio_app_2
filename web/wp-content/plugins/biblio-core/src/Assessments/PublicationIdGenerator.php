<?php
declare(strict_types=1);
namespace Biblio\Core\Assessments;
interface PublicationIdGenerator { public function next(): PublicationId; }
