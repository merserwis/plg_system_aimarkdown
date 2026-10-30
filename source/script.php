<?php
defined('_JEXEC') or die;

use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\Database\DatabaseInterface;

class PlgSystemAimarkdownInstallerScript extends InstallerScript
{
    /** Checked by InstallerScript::preflight() (same minimum as update.xml). */
    protected $minimumPhp    = '8.2.0';
    protected $minimumJoomla = '5.0.0';

    /** Version installed before this update ('' on a fresh install). */
    private string $previousVersion = '';

    public function preflight($type, $parent): bool
    {
        if ($type === 'update') {
            $this->previousVersion = $this->getInstalledVersion();
        }

        return parent::preflight($type, $parent);
    }

    /**
     * Since 1.6.3 the extension ships English, German, Polish, French, Czech and Dutch: the files of
     * the other languages installed by earlier versions are removed (Joomla keeps them on an update).
     */
    private function removeDroppedLanguages(): void
    {
        foreach (['ar-AA', 'es-ES', 'uk-UA', 'zh-CN'] as $tag) {
            $bases = [
                JPATH_ADMINISTRATOR . '/language/' . $tag . '/plg_system_aimarkdown',
                JPATH_PLUGINS . '/system/aimarkdown/language/' . $tag . '/plg_system_aimarkdown',
                JPATH_ADMINISTRATOR . '/language/' . $tag . '/mod_aimarkdown_dashboard',
                JPATH_ADMINISTRATOR . '/modules/mod_aimarkdown_dashboard/language/' . $tag . '/mod_aimarkdown_dashboard',
                JPATH_ADMINISTRATOR . '/language/' . $tag . '/com_aimarkdown',
                JPATH_ADMINISTRATOR . '/components/com_aimarkdown/language/' . $tag . '/com_aimarkdown',
            ];
            foreach ($bases as $base) {
                foreach (['.ini', '.sys.ini'] as $ext) {
                    if (is_file($base . $ext)) {
                        @unlink($base . $ext);
                    }
                }
            }
            foreach ([JPATH_PLUGINS . '/system/aimarkdown/language/' . $tag, JPATH_ADMINISTRATOR . '/modules/mod_aimarkdown_dashboard/language/' . $tag,
                JPATH_ADMINISTRATOR . '/components/com_aimarkdown/language/' . $tag] as $dir) {
                if (is_dir($dir) && !(new \FilesystemIterator($dir))->valid()) {
                    @rmdir($dir);
                }
            }
        }
    }

    public function postflight(string $type, $parent): void
    {
        if ($type === 'uninstall') {
            return;
        }

        $this->createAnalyticsTable();
        $this->removeDroppedLanguages();
        $this->removeObsoleteFiles();

        if ($type === 'update' && $this->previousVersion !== '' && version_compare($this->previousVersion, '1.5.4', '<')) {
            $this->migrateGridboxUrlMode();
        }

        if ($type === 'update' && $this->previousVersion !== '' && version_compare($this->previousVersion, '1.6.0', '<')) {
            $this->migrateMenuType();
        }

        if ($type === 'update' && $this->previousVersion !== '' && version_compare($this->previousVersion, '1.6.5', '<')) {
            $this->migrateBotTexts();
        }

        // Ordering + enabling only on a fresh install: an update must not re-enable a plugin
        // the administrator switched off, nor move it after the admin changed the order.
        if ($type === 'install' || $type === 'discover_install') {
            $this->setPluginPosition();
        }

        $this->clearCaches();
        $this->recordSiteBase();
    }

    /** The site address for /llms.txt generations started by visitors (see AiMarkdown::isTrustedHost). */
    private function recordSiteBase(): void
    {
        try {
            // only a browser request knows the site address (the CLI installer does not)
            if (!Factory::getApplication()->isClient('administrator')) {
                return;
            }
            $dir = JPATH_CACHE . '/plg_system_aimarkdown';
            if (is_dir($dir) || @mkdir($dir, 0755, true) || is_dir($dir)) {
                @file_put_contents($dir . '/llms.base', rtrim(\Joomla\CMS\Uri\Uri::root(), '/'));
            }
        } catch (\Throwable $e) {
        }
    }

    public function uninstall($parent): bool
    {
        try {
            $db = $this->getDatabase();
            $db->setQuery('DROP TABLE IF EXISTS ' . $db->quoteName('#__aimarkdown_logs'))->execute();
        } catch (\Throwable $e) {
        }

        // /llms.txt made by this plugin is served by the web server directly: it goes with the plugin
        // (a file kept by hand, or changed after the plugin wrote it, stays)
        $dir = JPATH_CACHE . '/plg_system_aimarkdown';
        if (is_file(JPATH_ROOT . '/llms.txt')) {
            $own = is_file($dir . '/llms.owner')
                ? hash_equals(trim((string) @file_get_contents($dir . '/llms.owner')), (string) @hash_file('sha256', JPATH_ROOT . '/llms.txt'))
                : is_file($dir . '/llms.attempt');
            if ($own) {
                @unlink(JPATH_ROOT . '/llms.txt');
                // the file that was there before the plugin's first generation comes back
                if (is_file($dir . '/llms.original.txt')) {
                    @copy($dir . '/llms.original.txt', JPATH_ROOT . '/llms.txt');
                }
            }
        }
        $this->deleteDirectoryRecursively(JPATH_CACHE . '/plg_system_aimarkdown');

        return true;
    }

    private function getDatabase(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }

    private function getInstalledVersion(): string
    {
        try {
            $db    = $this->getDatabase();
            $query = $db->getQuery(true)
                ->select($db->quoteName('manifest_cache'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('aimarkdown'));
            $manifest = json_decode((string) $db->setQuery($query)->loadResult(), true);

            return is_array($manifest) ? (string) ($manifest['version'] ?? '') : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * 1.5.2 shipped llms_gridbox_url_mode = "alias" (404 links for pages under app/category paths) and
     * 1.5.3 "router" (working links, but outside the app's menu item, so not the canonical URL).
     * From 1.5.4 "menu" (canonical, e.g. /oferta/...) is the default; switch installs on either.
     */
    /**
     * Up to 1.5.x the 'Main sections' menu defaulted to "main" — the administrator menu on almost
     * every site, so the block stayed empty. Without a site menu of that name: all site menus.
     */
    private function migrateMenuType(): void
    {
        try {
            $db    = $this->getDatabase();
            $where = [
                $db->quoteName('type') . ' = ' . $db->quote('plugin'),
                $db->quoteName('folder') . ' = ' . $db->quote('system'),
                $db->quoteName('element') . ' = ' . $db->quote('aimarkdown'),
            ];
            $query  = $db->getQuery(true)->select($db->quoteName('params'))->from($db->quoteName('#__extensions'))->where($where);
            $params = json_decode((string) $db->setQuery($query)->loadResult(), true);
            if (!is_array($params) || ($params['llms_menutype'] ?? 'main') !== 'main') {
                return;
            }
            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__menu_types'))
                ->where($db->quoteName('menutype') . ' = ' . $db->quote('main'))
                ->where($db->quoteName('client_id') . ' = 0');
            if ((int) $db->setQuery($query)->loadResult() > 0) {
                return;
            }
            $params['llms_menutype'] = '';
            $db->setQuery($db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('params') . ' = ' . $db->quote(json_encode($params)))
                ->where($where))->execute();
        } catch (\Throwable $e) {
        }
    }

    /**
     * Up to 1.6.4 the texts for AI models were fixed Polish texts written for a shop with measuring
     * instruments, and missing FAQs were generated by default. From 1.6.5 they come from the language
     * files (neutral) and the generated FAQ is off by default. An update must not change what a site
     * already gives to AI models: the generated FAQ stays on where it was on, and a site in Polish keeps
     * the former FAQ set and the former wording of the industry-specific /llms.txt texts (in the
     * fields of the tab "Texts for AI", which can be cleared). Other sites get the neutral FAQ in
     * their language instead of the Polish one.
     */
    private function migrateBotTexts(): void
    {
        try {
            $db    = $this->getDatabase();
            $where = [
                $db->quoteName('type') . ' = ' . $db->quote('plugin'),
                $db->quoteName('folder') . ' = ' . $db->quote('system'),
                $db->quoteName('element') . ' = ' . $db->quote('aimarkdown'),
            ];
            $query  = $db->getQuery(true)->select($db->quoteName('params'))->from($db->quoteName('#__extensions'))->where($where);
            $params = json_decode((string) $db->setQuery($query)->loadResult(), true);
            if (!is_array($params)) {
                $params = [];
            }

            // the former default: on when never saved
            $faqOn = (int) ($params['auto_generate_faq'] ?? 1) === 1;
            $params['auto_generate_faq'] = $faqOn ? 1 : 0;

            $polish = (string) \Joomla\CMS\Component\ComponentHelper::getParams('com_languages')->get('site', 'en-GB') === 'pl-PL';
            if ($polish) {
                if ($faqOn && empty($params['auto_faq_set'])) {
                    $params['auto_faq_set'] = 'instruments';
                }
                $former = [
                    'llms_title_popular' => 'Najpopularniejsze produkty i aparatura pomiarowa',
                    'llms_title_latest'  => 'Produkty, wdrożenia i baza wiedzy',
                    'llms_desc_category' => 'Oferta i aparatura w kategorii: {title}.',
                    'llms_desc_item'     => 'Szczegółowy opis, analiza wdrożenia i specyfikacja.',
                ];
                foreach ($former as $key => $text) {
                    if (trim((string) ($params[$key] ?? '')) === '') {
                        $params[$key] = $text;
                    }
                }
            }

            $db->setQuery($db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('params') . ' = ' . $db->quote(json_encode($params)))
                ->where($where))->execute();

            if ($polish) {
                Factory::getApplication()->enqueueMessage(Text::_('PLG_SYSTEM_AIMARKDOWN_INSTALL_TEXTS_KEPT'), 'notice');
            }
        } catch (\Throwable $e) {
        }
    }

    private function migrateGridboxUrlMode(): void
    {
        try {
            $db    = $this->getDatabase();
            $where = [
                $db->quoteName('type') . ' = ' . $db->quote('plugin'),
                $db->quoteName('folder') . ' = ' . $db->quote('system'),
                $db->quoteName('element') . ' = ' . $db->quote('aimarkdown'),
            ];
            $query  = $db->getQuery(true)->select($db->quoteName('params'))->from($db->quoteName('#__extensions'))->where($where);
            $params = json_decode((string) $db->setQuery($query)->loadResult(), true);

            if (!is_array($params) || !in_array($params['llms_gridbox_url_mode'] ?? 'alias', ['alias', 'router'], true)) {
                return;
            }

            $params['llms_gridbox_url_mode'] = 'menu';
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('params') . ' = ' . $db->quote(json_encode($params)))
                ->where($where);
            $db->setQuery($query)->execute();

            Factory::getApplication()->enqueueMessage(Text::_('PLG_SYSTEM_AIMARKDOWN_INSTALL_URL_MODE_MIGRATED'), 'notice');
        } catch (\Throwable $e) {
        }
    }

    private function removeObsoleteFiles(): void
    {
        $obsoleteFiles = [
            JPATH_PLUGINS . '/system/aimarkdown/src/Field/LlmsGeneratorField.php',
            // until 1.6.7 the component was a bare redirect to the plugin settings; its pages are an MVC component now
            JPATH_ADMINISTRATOR . '/components/com_aimarkdown/aimarkdown.php',
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
            Factory::getApplication()->enqueueMessage(Text::sprintf('PLG_SYSTEM_AIMARKDOWN_INSTALL_LOG_TABLE_FAILED', $e->getMessage()), 'warning');
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
        // only the Markdown pages: the state of /llms.txt in the same folder (llms.owner, llms.attempt, llms.base and
        // llms.original.txt, the only copy of a file kept by hand before the first generation) must survive an update
        foreach (glob(JPATH_CACHE . '/plg_system_aimarkdown/*') ?: [] as $file) {
            if (is_file($file) && preg_match('/\.(md|tmp)$/', $file)) {
                @unlink($file);
            }
        }

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
