<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Settings\SettingDefinition;
use Biblio\Core\Settings\SettingState;
use Biblio\Core\Settings\SettingsRepository;
use Biblio\Core\Settings\SettingStale;
use wpdb;

final readonly class WpdbSettingsRepository implements SettingsRepository
{
    public function __construct(private wpdb $database, private CoreTableNames $tables)
    {
    }

    public function read(LibraryId $library, ?UserId $owner, string $setting): SettingState
    {
        SettingDefinition::validate($setting, null, $owner === null, 0);
        $table = $owner === null ? $this->tables->libraryDefaults() : $this->tables->personalSettings();
        $sql = "SELECT setting_value,setting_version FROM `{$table}` WHERE library_id=%s AND setting_key=%s";
        $args = [$library->value(), $setting];
        if ($owner !== null) {
            $sql .= ' AND user_id=%s';
            $args[] = $owner->value();
        }
        $row = $this->database->get_row($this->database->prepare($sql, ...$args), ARRAY_A);
        if ($this->database->last_error !== '') {
            throw new PersistenceException('Could not read settings.');
        }
        if ($row === null) {
            return new SettingState(null, 0);
        }
        $value = $row['setting_value'];

        return new SettingState(
            $value === null ? null : ($setting === SettingDefinition::ARCHIVE ? $value === '1' : $value),
            (int) $row['setting_version']
        );
    }

    public function save(LibraryId $library, ?UserId $owner, string $setting, string|bool|null $value, int $expectedVersion): void
    {
        SettingDefinition::validate($setting, $value, $owner === null, $expectedVersion);
        $table = $owner === null ? $this->tables->libraryDefaults() : $this->tables->personalSettings();
        $stored = is_bool($value) ? ($value ? '1' : '0') : $value;
        $previous = $this->database->suppress_errors(true);

        try {
            if ($expectedVersion === 0) {
                $data = ['library_id' => $library->value(), 'setting_key' => $setting, 'setting_value' => $stored, 'setting_version' => 1];
                if ($owner !== null) {
                    $data['user_id'] = $owner->value();
                }
                // Core IDs are strings; wpdb's global user_id integer format
                // must not truncate or collapse them.
                $formats = array_map(static fn (string $key): string => $key === 'setting_version' ? '%d' : '%s', array_keys($data));
                $result = $this->database->insert($table, $data, $formats);
                if ($result === false && $this->read($library, $owner, $setting)->version > 0) {
                    throw new SettingStale();
                }
            } else {
                $valueSql = $stored === null ? 'NULL' : $this->database->prepare('%s', $stored);
                $sql = "UPDATE `{$table}` SET setting_value={$valueSql},setting_version=setting_version+1 WHERE library_id=%s AND setting_key=%s AND setting_version=%d";
                $args = [$library->value(), $setting, $expectedVersion];
                if ($owner !== null) {
                    $sql .= ' AND user_id=%s';
                    $args[] = $owner->value();
                }
                // One atomic mutation: a stale version can never overwrite a
                // newer setting, including a reset followed by a new override.
                $result = $this->database->query($this->database->prepare($sql, ...$args));
            }
            if ($result === false) {
                throw new PersistenceException('Could not save settings.');
            }
            if ($result !== 1) {
                throw new SettingStale();
            }
        } finally {
            $this->database->suppress_errors($previous);
        }
    }
}
