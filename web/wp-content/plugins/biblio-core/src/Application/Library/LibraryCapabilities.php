<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Library;

final readonly class LibraryCapabilities
{
    public function __construct(
        private bool $viewCollection,
        private bool $addCatalogItem,
        private bool $modifyCatalogContext,
        private bool $manageClassificationTerms,
        private bool $publishContribution,
        private bool $moderateContribution,
        private bool $useItemDirectly,
        private bool $receiveInternalLoan
    ) {
    }

    public function canViewCollection(): bool { return $this->viewCollection; }
    public function canAddCatalogItem(): bool { return $this->addCatalogItem; }
    public function canModifyCatalogContext(): bool { return $this->modifyCatalogContext; }
    public function canManageClassificationTerms(): bool { return $this->manageClassificationTerms; }
    public function canPublishContribution(): bool { return $this->publishContribution; }
    public function canModerateContribution(): bool { return $this->moderateContribution; }
    public function canUseItemDirectly(): bool { return $this->useItemDirectly; }
    public function canReceiveInternalLoan(): bool { return $this->receiveInternalLoan; }
}
