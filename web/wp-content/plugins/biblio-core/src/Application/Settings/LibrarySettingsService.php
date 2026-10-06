<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Settings;

use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Library\LibraryContextQueryService;
use Biblio\Core\Application\Library\LibraryContextView;
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Settings\SettingDefinition;
use Biblio\Core\Settings\SettingsRepository;

final readonly class LibrarySettingsService
{
    public function __construct(
        private AuthenticatedUser $actor,
        private LibraryContextQueryService $contexts,
        private SettingsRepository $repository
    ) {
    }

    /** @return array<string, mixed> */
    public function preferences(LibraryId $id): array
    {
        $actor = $this->actor->requireUserId();
        $library = $this->contexts->get($id);
        $shared = $this->repository->read($id, null, SettingDefinition::VIEW);
        $view = $this->repository->read($id, $actor, SettingDefinition::VIEW);
        $archive = $this->repository->read($id, $actor, SettingDefinition::ARCHIVE);
        $defaultView = $shared->value ?? 'grid';
        $defaultSource = $shared->value === null ? 'biblio' : 'library';

        return [
            'library' => ['library_id' => $id->value(), 'name' => $library->name()->value()],
            'capabilities' => ['manage_defaults' => $library->capabilities()->canManageLibraryDefaults()],
            'preferences' => [
                SettingDefinition::VIEW => array_merge(
                    $view->projection($defaultView, $defaultSource),
                    [
                        // Only inherited defaults participate in temporary view
                        // invalidation. An explicit personal choice stays intact.
                        'default_version' => $view->value === null ? $shared->version : null,
                        'default_effective' => $defaultView,
                        'default_source' => $defaultSource,
                    ]
                ),
                SettingDefinition::ARCHIVE => $archive->projection(false, 'biblio'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function defaults(LibraryId $id): array
    {
        $library = $this->authorizeDefault($id);
        $state = $this->repository->read($id, null, SettingDefinition::VIEW);
        $projection = $state->projection('grid', 'biblio');
        $projection['source'] = $state->value === null ? 'biblio' : 'library';

        return [
            'library' => ['library_id' => $id->value(), 'name' => $library->name()->value()],
            'capabilities' => ['manage_defaults' => true],
            'defaults' => [SettingDefinition::VIEW => $projection],
        ];
    }

    /** @return array<string, mixed> */
    public function changePersonal(LibraryId $id, string $setting, mixed $value, int $expectedVersion): array
    {
        $actor = $this->actor->requireUserId();
        $this->contexts->get($id);
        SettingDefinition::validate($setting, $value, false, $expectedVersion);
        $this->repository->save($id, $actor, $setting, $value, $expectedVersion);

        return $this->preferences($id);
    }

    /** @return array<string, mixed> */
    public function changeDefault(LibraryId $id, string $setting, mixed $value, int $expectedVersion): array
    {
        $this->authorizeDefault($id);
        SettingDefinition::validate($setting, $value, true, $expectedVersion);
        $this->repository->save($id, null, $setting, $value, $expectedVersion);

        return $this->defaults($id);
    }

    private function authorizeDefault(LibraryId $id): LibraryContextView
    {
        $library = $this->contexts->get($id);
        if (!$library->capabilities()->canManageLibraryDefaults()) {
            throw new AuthorizationException('Library default management is unavailable.');
        }

        return $library;
    }
}
