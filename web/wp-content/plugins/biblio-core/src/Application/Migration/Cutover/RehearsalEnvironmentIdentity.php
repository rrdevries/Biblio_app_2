<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

/** Two independent observations: process/runtime and a database-resident marker. */
final class RehearsalEnvironmentIdentity
{
    /**
 * @param array<string,mixed> $actual
 * @param array<string,mixed> $expected
 */
    public static function assert(array $actual, array $expected): void
    {
        RehearsalContract::keys($actual, [
            "environment_id", "project", "database", "root", "git_sha", "dirty",
            "runtime", "marker", "target_user_id", "target_library_id",
            "roles", "super_admin", "environment_type", "writes_frozen",
        ]);
        RehearsalContract::require(
            is_string($actual["project"]) && preg_match('/^biblio-v2-cutover-[a-f0-9]{12}$/D', $actual["project"]) === 1
            && is_string($actual["database"]) && preg_match('/^biblio_cutover_[a-f0-9]{12}$/D', $actual["database"]) === 1,
            "production_or_unknown_target"
        );
        RehearsalContract::require($actual["environment_type"] === "local"
            && $actual["writes_frozen"] === true, "rehearsal_write_freeze_missing");
        RehearsalContract::require($actual["dirty"] === false
            && is_string($actual["git_sha"]) && preg_match('/^[a-f0-9]{40}$/D', $actual["git_sha"]) === 1, "rehearsal_build_invalid");
        RehearsalContract::require($actual["roles"] === ["subscriber"]
            && $actual["super_admin"] === false, "ordinary_target_user_required");
        RehearsalContract::id($actual["environment_id"]);
        RehearsalContract::id($actual["target_user_id"]);
        RehearsalContract::id($actual["target_library_id"]);
        RehearsalContract::equal($actual["marker"], [
            "purpose" => "MIG-CUTOVER-PREP-01B",
            "environment_id" => $actual["environment_id"],
            "project" => $actual["project"],
            "database" => $actual["database"],
            "git_sha" => $actual["git_sha"],
        ], "positive_rehearsal_marker_missing");
        RehearsalContract::require(is_array($actual["runtime"]), "runtime_missing");
        RehearsalContract::keys($actual["runtime"], [
            "wordpress", "php", "mariadb", "charset", "collation", "sql_mode",
            "extensions", "product", "core", "ui", "schema", "ddev", "dependency_sha256",
        ]);
        RehearsalContract::require($actual["runtime"]["schema"] === 1026
            && $actual["runtime"]["product"] === "v2.001", "runtime_schema_mismatch");
        foreach (["wordpress", "php", "mariadb", "charset", "collation", "core", "ui", "ddev"] as $field) {
            RehearsalContract::require(is_string($actual["runtime"][$field]) && trim($actual["runtime"][$field]) !== "", "runtime_value_missing");
        }
        RehearsalContract::hash($actual["runtime"]["dependency_sha256"]);
        RehearsalContract::require(is_string($actual["root"]) && str_starts_with($actual["root"], "/")
            && is_string($actual["runtime"]["sql_mode"]) && is_array($actual["runtime"]["extensions"]), "runtime_invalid");
        foreach (["json", "mbstring", "mysqli", "openssl", "pdo_mysql", "zip"] as $extension) {
            RehearsalContract::require(in_array($extension, $actual["runtime"]["extensions"], true), "required_extension_missing");
        }
        // Conservative exact equality also blocks patch drift until a new specification is reviewed.
        RehearsalContract::equal($actual, $expected, "accepted_environment_or_runtime_changed");
    }
}
