<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Filesystem\Folder;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\CMS\Table\Table;

/**
 * Installation and update script for System - AI Markdown for Gridbox plugin.
 */
class PlgSystemAimarkdownInstallerScript extends InstallerScript
{
    public function install($parent): void
    {
        $this->setPluginPosition();
        $this->createAnalyticsTable();
        $this->installDashboardModule();
        $this->registerComponentProxyAndMenu();
    }

    public function update($parent): void
    {
        $this->setPluginPosition();
        $this->createAnalyticsTable();
        $this->installDashboardModule();
        $this->registerComponentProxyAndMenu();
    }

    public function postflight(string $type, $parent): void
    {
        $this->setPluginPosition();
        $this->createAnalyticsTable();
        $this->installDashboardModule();
        $this->registerComponentProxyAndMenu();
        $this->clearJoomlaCache();
    }

    public function uninstall($parent): void
    {
        $this->removeComponentProxyAndMenu();
    }

    /**
     * Rejestruje komponent proxy oraz poprawny węzeł drzewa w #__menu pod Komponentami
     */
    private function registerComponentProxyAndMenu(): void
    {
        try {
            $db = Factory::getDbo();

            // 1. Zarejestruj com_aimarkdown w #__extensions jako type='component'
            $query = $db->getQuery(true)
                ->select($db->quoteName('extension_id'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_aimarkdown'));
            $db->setQuery($query);
            $comId = (int) $db->loadResult();

            if (!$comId) {
                $comExt = new \stdClass();
                $comExt->name           = 'com_aimarkdown';
                $comExt->type           = 'component';
                $comExt->element        = 'com_aimarkdown';
                $comExt->folder         = '';
                $comExt->client_id      = 1;
                $comExt->enabled        = 1;
                $comExt->access         = 1;
                $comExt->protected      = 0;
                $comExt->manifest_cache = json_encode([
                    'name'        => 'com_aimarkdown',
                    'type'        => 'component',
                    'version'     => '1.4.0',
                    'description' => 'AI Markdown for Gridbox Proxy',
                ]);

                $db->insertObject('#__extensions', $comExt, 'extension_id');
                $comId = (int) $comExt->extension_id;
            }

            // 2. Utwórz plik manifestu komponentu na dysku, aby Joomla go autoryzowała
            $compDir = JPATH_ADMINISTRATOR . '/components/com_aimarkdown';
            if (!is_dir($compDir)) {
                Folder::create($compDir);
            }

            $manifestXml = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
                . '<extension type="component" client="administrator" method="upgrade">' . "\n"
                . '    <name>com_aimarkdown</name>' . "\n"
                . '    <version>1.4.0</version>' . "\n"
                . '    <description>AI Markdown for Gridbox</description>' . "\n"
                . '</extension>';
            File::write($compDir . '/aimarkdown.xml', $manifestXml);

            // 3. Sprawdź czy pozycja menu już istnieje w #__menu
            $query = $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__menu'))
                ->where($db->quoteName('client_id') . ' = 1')
                ->where($db->quoteName('menutype') . ' = ' . $db->quote('main'))
                ->where($db->quoteName('component_id') . ' = ' . $comId);
            $db->setQuery($query);
            $menuId = (int) $db->loadResult();

            // Użyj natywnej klasy tabeli Joomla do wyliczenia gałęzi drzewa (lft/rgt)
            $table = Table::getInstance('Menu', 'Joomla\\CMS\\Table\\');
            if (!$table) {
                $table = new \Joomla\CMS\Table\Menu($db);
            }

            if ($menuId) {
                $table->load($menuId);
                $table->published = 1;
                $table->title     = 'AI Markdown for Gridbox';
                $table->link      = 'index.php?option=com_aimarkdown';
                $table->store();
            } else {
                $menuData = [
                    'menutype'     => 'main',
                    'title'        => 'AI Markdown for Gridbox',
                    'alias'        => 'ai-markdown-gridbox',
                    'link'         => 'index.php?option=com_aimarkdown',
                    'type'         => 'component',
                    'published'    => 1,
                    'parent_id'    => 1,
                    'component_id' => $comId,
                    'client_id'    => 1,
                    'access'       => 1,
                    'img'          => 'class:robot',
                    'language'     => '*',
                ];

                $table->setLocation(1, 'last-child');
                $table->save($menuData);
            }
        } catch (\Throwable $e) {
            // Ciche wyjście
        }
    }

    private function removeComponentProxyAndMenu(): void
    {
        try {
            $db = Factory::getDbo();

            // Usuń z #__menu
            $query = $db->getQuery(true)
                ->delete($db->quoteName('#__menu'))
                ->where($db->quoteName('link') . ' = ' . $db->quote('index.php?option=com_aimarkdown'))
                ->where($db->quoteName('client_id') . ' = 1');
            $db->setQuery($query);
            $db->execute();

            // Usuń z #__extensions
            $query = $db->getQuery(true)
                ->delete($db->quoteName('#__extensions'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_aimarkdown'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'));
            $db->setQuery($query);
            $db->execute();

            // Usuń katalog
            $compDir = JPATH_ADMINISTRATOR . '/components/com_aimarkdown';
            if (is_dir($compDir)) {
                Folder::delete($compDir);
            }
        } catch (\Throwable $e) {
        }
    }

    private function installDashboardModule(): void
    {
        try {
            $srcDir  = __DIR__ . '/modules/mod_aimarkdown_dashboard';
            $destDir = JPATH_ADMINISTRATOR . '/modules/mod_aimarkdown_dashboard';

            if (!is_dir($srcDir)) {
                return;
            }

            if (!is_dir($destDir)) {
                Folder::create($destDir);
            }

            File::copy($srcDir . '/mod_aimarkdown_dashboard.xml', $destDir . '/mod_aimarkdown_dashboard.xml');
            File::copy($srcDir . '/mod_aimarkdown_dashboard.php', $destDir . '/mod_aimarkdown_dashboard.php');

            $db = Factory::getDbo();

            $query = $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__modules'))
                ->where($db->quoteName('module') . ' = ' . $db->quote('mod_aimarkdown_dashboard'))
                ->where($db->quoteName('client_id') . ' = 1');
            $db->setQuery($query);
            $modInstanceId = (int) $db->loadResult();

            if (!$modInstanceId) {
                $columns = ['title', 'content', 'ordering', 'position', 'checked_out', 'checked_out_time', 'published', 'module', 'numnews', 'access', 'showtitle', 'params', 'client_id', 'language'];
                $values = [
                    $db->quote('AI Crawler Monitor'),
                    $db->quote(''),
                    1,
                    $db->quote('cpanel'),
                    0,
                    $db->quote('1970-01-01 00:00:00'),
                    1,
                    $db->quote('mod_aimarkdown_dashboard'),
                    0,
                    1,
                    1,
                    $db->quote('{}'),
                    1,
                    $db->quote('*')
                ];

                $query = $db->getQuery(true)
                    ->insert($db->quoteName('#__modules'))
                    ->columns($columns)
                    ->values(implode(',', $values));
                $db->setQuery($query);
                $db->execute();
            }
        } catch (\Throwable $e) {
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