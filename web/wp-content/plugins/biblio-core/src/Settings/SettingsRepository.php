<?php

declare(strict_types=1);
namespace Biblio\Core\Settings;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;

interface SettingsRepository
{
    public function read(LibraryId $library, ?UserId $owner, string $setting): SettingState;
    public function save(LibraryId $library, ?UserId $owner, string $setting, string|bool|null $value, int $expectedVersion): void;
}
