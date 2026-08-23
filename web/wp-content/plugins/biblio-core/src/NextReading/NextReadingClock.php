<?php
declare(strict_types=1);
namespace Biblio\Core\NextReading;
use DateTimeImmutable;
interface NextReadingClock { public function now(): DateTimeImmutable; }
