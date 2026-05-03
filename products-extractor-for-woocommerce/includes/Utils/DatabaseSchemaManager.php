<?php

declare(strict_types=1);

namespace Torob\Utils;

use Torob\ProductWebhook\WebhookQueueRepository;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Runs plugin database schema migrations.
 */
final class DatabaseSchemaManager
{
    public const DB_VERSION = 1;

    /**
     * Prevent instantiation of a static utility.
     */
    private function __construct() {}

    /**
     * Check whether stored schema version is older than this plugin version expects.
     */
    public static function needsMigration(): bool
    {
        return Options::getDbVersion() < self::DB_VERSION;
    }

    /**
     * Run pending schema migrations in order and persist each completed version.
     */
    public static function migrateIfNeeded(): void
    {
        $stored_version = Options::getDbVersion();

        if ($stored_version >= self::DB_VERSION) {
            return;
        }

        for ($version = $stored_version + 1; $version <= self::DB_VERSION; $version++) {
            self::migrateToVersion($version);
            Options::setDbVersion($version);
        }
    }

    /**
     * Apply a single schema migration version.
     */
    private static function migrateToVersion(int $version): void
    {
        switch ($version) {
            case 1:
                self::migrateToVersion1();
                return;
            default:
                throw new \InvalidArgumentException(sprintf('Unknown database schema migration version: %d', $version));
        }
    }

    /**
     * Install the initial plugin schema.
     */
    private static function migrateToVersion1(): void
    {
        WebhookQueueRepository::create_or_update_table_schema();
    }
}
