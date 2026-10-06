<?php

declare(strict_types=1);
namespace Biblio\Core\Settings;
use Biblio\Core\Exception\ValidationException;
final class SettingDefinition
{
    public const VIEW='catalog_view';
    public const ARCHIVE='catalog_archive_visible';
    public static function validate(string $setting, mixed $value, bool $shared, int $version): void
    {
        if ($version < 0 || $version >= 9007199254740991
            || !in_array($setting, $shared ? [self::VIEW] : [self::VIEW,self::ARCHIVE], true)
            || ($value !== null && ($setting === self::VIEW
                ? !in_array($value,['grid','list'],true) : !is_bool($value)))) {
            throw new ValidationException('Invalid setting, value or revision.');
        }
    }
}
