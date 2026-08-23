<?php
declare(strict_types=1);
namespace Biblio\Core\NextReading;
interface NextReadingEntryIdGenerator { public function next(): NextReadingEntryId; }
