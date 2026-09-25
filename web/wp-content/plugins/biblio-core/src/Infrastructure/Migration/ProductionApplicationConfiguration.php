<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Cutover\RehearsalContract;

/** This cutover supports the audited local DDEV site, not arbitrary PHP deployments. */
final class ProductionApplicationConfiguration
{
    public static function assertAudited(string $root): void
    {
        // Hashes bind the audited include/credential path, not the credentials themselves.
        $files = [
            'wp-config.php' => '9472b3761f9e4ca8705d50c4170fd297c715ffbfb679d3160716057c839f0de2',
            'wp-config-ddev.php' => '1bb421ed69a1e58575a4c4000f1352b00e3bee46f978723b84757c77bde6fffc',
        ];
        foreach ($files as $name => $hash) {
            $path = $root . '/web/' . $name;
            RehearsalContract::require(is_file($path) && !is_link($path) && realpath($path) === $path, 'production_config_unsafe');
            RehearsalContract::equal(hash_file('sha256', $path), $hash, 'production_app_config_changed');
        }
        foreach (['DB_USER' => 'db', 'DB_NAME' => 'db', 'DB_HOST' => 'db', 'DB_PREFIX' => 'wp_'] as $key => $value) {
            RehearsalContract::require(!defined($key) && (getenv($key) === false || getenv($key) === $value), 'production_app_credential_override');
        }
        RehearsalContract::require(getenv('IS_DDEV_PROJECT') === 'true' && getenv('DDEV_DOCROOT') === 'web'
            && getenv('DDEV_PRIMARY_URL') === 'https://biblio-v2.ddev.site' && ini_get('auto_prepend_file') === '', 'production_app_runtime_changed');
    }
}
