<?php
defined('_JEXEC') or die;

use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\Database\DatabaseInterface;

class PlgSystemAimarkdownInstallerScript extends InstallerScript
{
    /** Checked by InstallerScript::preflight() (same minimum as update.xml). */
    protected $minimumPhp    = '8.2.0';
    protected $minimumJoomla = '5.0.0';

    public function postflight(string $type, $parent): void
    {
        if ($type === 'uninstall') {
            return;
        }

        $this->createAnalyticsTable();
        $this->removeObsoleteFiles();

        // Ordering + enabling only on a fresh install: an update must not re-enable a plugin
        // the administrator switched off, nor move it after the admin changed the order.
        if ($type === 'install' || $type === 'discover_install') {
            $this->setPluginPosition();
        }

        $this->clearCaches();
    }

    public function uninstall($parent): bool
    {
        try {
            $db = $this->getDatabase();
            $db->setQuery('DROP TABLE IF EXISTS ' . $db->quoteName('#__aimarkdown_logs'))->execute();
        } catch (\Throwable $e) {
        }

        $this->deleteDirectoryRecursively(JPATH_CACHE . '/plg_system_aimarkdown');

        return true;
    }

    private function getDatabase(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    private function removeObsoleteFiles(): void
    {
        $obsoleteFiles = [
            JPATH_PLUGINS . '/system/aimarkdown/src/Field/LlmsGeneratorField.php',
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
            $db  = $this->getDatabase();
            $sql = 'CREATE TABLE IF NOT EXISTS ' . $db->quoteName('#__aimarkdown_logs') . ' (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `bot_name` VARCHAR(64) NOT NULL,
                `url` VARCHAR(2048) NOT NULL,
                `ip_address` VARCHAR(45) NOT NULL DEFAULT \'\',
                `user_agent` VARCHAR(512) NOT NULL DEFAULT \'\',
                `is_cache_hit` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL,
                INDEX `idx_created_at` (`created_at`),
                INDEX `idx_bot_name` (`bot_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci';

            $db->setQuery($sql)->execute();
        } catch (\Throwable $e) {
            Factory::getApplication()->enqueueMessage('AI Markdown: nie można utworzyć tabeli logów: ' . $e->getMessage(), 'warning');
        }
    }

    private function setPluginPosition(): void
    {
        try {
            $db    = $this->getDatabase();
            $query = $db->getQuery(true)
                ->select('MAX(' . $db->quoteName('ordering') . ')')
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'));
            $maxOrdering = (int) $db->setQuery($query)->loadResult();

            $query = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('ordering') . ' = ' . ($maxOrdering + 100))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('aimarkdown'));
            $db->setQuery($query)->execute();
        } catch (\Throwable $e) {
        }
    }

    /**
     * Drop the plugin's Markdown cache and Joomla's page cache (pages cached before the update lack the
     * alternate link). Previously the whole site/administrator cache folders were wiped and the entire
     * OPcache was reset, which also hit every other extension and site sharing the PHP pool.
     */
    private function clearCaches(): void
    {
        $this->deleteDirectoryRecursively(JPATH_CACHE . '/plg_system_aimarkdown');

        try {
            $cache = Factory::getContainer()->get(CacheControllerFactoryInterface::class)
                ->createCacheController('callback');
            $cache->clean('page');
        } catch (\Throwable $e) {
        }

        if (function_exists('opcache_invalidate')) {
            $dir = JPATH_PLUGINS . '/system/aimarkdown';
            if (is_dir($dir)) {
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
                foreach ($files as $file) {
                    if ($file->getExtension() === 'php') {
                        @opcache_invalidate($file->getPathname(), true);
                    }
                }
            }
        }
    }

    private function deleteDirectoryRecursively(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $file) {
            $filePath = $dir . '/' . $file;
            is_dir($filePath) && !is_link($filePath) ? $this->deleteDirectoryRecursively($filePath) : @unlink($filePath);
        }

        @rmdir($dir);
    }
}
