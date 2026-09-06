<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

final readonly class AddBookMetadataReviewPolicy
{
    /** @return list<MetadataFieldBinding> */
    public function fieldBindings(): array
    {
        return [
            new MetadataFieldBinding(
                MetadataField::Title,
                MetadataBindingTarget::EditionTitleEvidence
            ),
            new MetadataFieldBinding(
                MetadataField::Subtitle,
                MetadataBindingTarget::Edition
            ),
            new MetadataFieldBinding(
                MetadataField::Languages,
                MetadataBindingTarget::Edition
            ),
            new MetadataFieldBinding(
                MetadataField::Publishers,
                MetadataBindingTarget::Edition
            ),
            new MetadataFieldBinding(
                MetadataField::PublicationDate,
                MetadataBindingTarget::Edition
            ),
            new MetadataFieldBinding(
                MetadataField::PageCount,
                MetadataBindingTarget::Edition
            ),
            new MetadataFieldBinding(
                MetadataField::Contributors,
                MetadataBindingTarget::EvidenceOnly,
                [
                    "author" => MetadataBindingTarget::Work,
                    "co_author" => MetadataBindingTarget::Work,
                    "translator" => MetadataBindingTarget::Edition,
                    "illustrator" => MetadataBindingTarget::Edition,
                    "editor" => MetadataBindingTarget::Edition,
                    "compiler" => MetadataBindingTarget::Edition,
                ],
                MetadataBindingTarget::EvidenceOnly
            ),
            new MetadataFieldBinding(
                MetadataField::Format,
                MetadataBindingTarget::EvidenceOnly,
                [],
                MetadataBindingTarget::EvidenceOnly
            ),
        ];
    }
}
