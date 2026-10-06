<?php

declare(strict_types=1);
namespace Biblio\Core\Settings;
use Biblio\Core\Exception\{ConflictException,FailureReason};
final class SettingStale extends ConflictException
{
    public function __construct() { parent::__construct('Setting changed since it was loaded.', FailureReason::SettingStale); }
}
