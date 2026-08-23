<?php
declare(strict_types=1);namespace Biblio\Core\Infrastructure\WordPress;use Biblio\Core\Assessments\{PublicationId,PublicationIdGenerator};final readonly class OpaquePublicationIdGenerator implements PublicationIdGenerator{public function next():PublicationId{return new PublicationId('publication-'.bin2hex(random_bytes(16)));}}
