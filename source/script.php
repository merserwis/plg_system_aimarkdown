<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Filesystem\Folder;
use Joomla\CMS\Installer\InstallerScript;

/**
 * Installation and update script for System - AI Markdown for Gridbox plugin.
 */
class PlgSystemAimarkdownInstallerScript extends InstallerScript
{
    public function install($parent): void
    {
        $this->setPluginPosition();
        $this->createAnalyticsTable();
        $this->removeObsoleteFiles();
    }

    public function update($parent): void
    {
        $this->setPluginPosition();
        $this->createAnalyticsTable();
        $this->removeObsoleteFiles();
    }

    public function postflight(string $type, $parent): void
    {
        $this->setPluginPosition();
        $this->createAnalyticsTable();
        $this->removeObsoleteFiles();
        $this->clearJoomlaCache();
    }

    /**
     * Remove obsolete files from earlier versions.
     */
    private function removeObsoleteFiles(): void
    {
        $obsoleteFiles = [
            JPATH_SITE . '/plugins/system/aimarkdown/src/Field/LlmsGeneratorField.php',
        ];

        foreach ($obsoleteFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private function createAnalyticsTable(): void
    {
        try {
            $db = Factory::getDbo();
            $sql = "CREATE TABLE IF NOT EXISTS `#__aimarkdown_logs` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `bot_name` VARCHAR(64) NOT NULL,
                `url` VARCHAR(2048) NOT NULL,
                `ip_address` VARCHAR(45) NOT NULL DEFAULT '',
                `user_agent` VARCHAR(512) NOT NULL DEFAULT '',
                `is_cache_hit` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL,
                INDEX `idx_created_at` (`created_at`),
                INDEX `idx_bot_name` (`bot_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;";

            $db->setQuery($sql);
            $db->execute();
        } catch (\Throwable $e) {
        }
    }

    private function setPluginPosition(): void
    {
        try {
            $db = Factory::getDbo();
            $query = $db->getQuery(true)
                ->select('MAX(' . $db->quoteName('ordering') . ')')
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'));
            $db->setQuery($query);
            $maxOrdering = (int) $db->loadResult();

            $query = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('ordering') . ' = ' . ($maxOrdering + 100))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('aimarkdown'));
            $db->setQuery($query);
            $db->execute();
        } catch (\Throwable $e) {
        }
    }

    private function clearJoomlaCache(): void
    {
        try {
            $cache = Factory::getCache('', '');
            $cache->clean();
        } catch (\Throwable $e) {
        }

        $cachePaths = [
            JPATH_SITE . '/cache',
            JPATH_ADMINISTRATOR . '/cache',
        ];

        foreach ($cachePaths as $path) {
            if (is_dir($path)) {
                $this->purgeCacheDirectory($path);
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    private function purgeCacheDirectory(string $dir): void
    {
        $items = @scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === 'index.html' || $item === '.gitignore') {
                continue;
            }

            $fullPath = $dir . '/' . $item;

            if (is_dir($fullPath)) {
                $this->deleteDirectoryRecursively($fullPath);
            } elseif (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }
    }

    private function deleteDirectoryRecursively(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $filePath = $dir . '/' . $file;
            is_dir($filePath) ? $this->deleteDirectoryRecursively($filePath) : @unlink($filePath);
        }

        @rmdir($dir);
    }
}