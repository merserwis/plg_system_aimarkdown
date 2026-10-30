<?php

/**
 * @package     Merserwis.Component
 * @subpackage  com_aimarkdown
 *
 * The settings of AI Markdown are, as they always were, the parameters of its system plugin
 * (#__extensions): the plugin reads them and nothing about that changes. This class reads them, builds
 * the form of a page from the fieldset of the plugin's manifest, and saves through Joomla's own plugin
 * model, so everything that follows a save of the plugin in Joomla follows here too (the plugin's
 * onExtensionAfterSave empties the cache and removes a switched-off /llms.txt, Joomla cleans its cache
 * of the plugins, the action log notes who saved).
 */

namespace Merserwis\Component\AiMarkdown\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormFactoryInterface;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

final class Settings
{
    /** the row of the plugin (false: not read yet) */
    private static object|false|null $plugin = false;

    private static ?\SimpleXMLElement $manifest = null;

    private static ?array $defaults = null;

    /** the plugin's row: extension_id, enabled, params; null when it is not installed */
    public static function plugin(): ?object
    {
        if (self::$plugin === false) {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->createQuery()
                ->select($db->quoteName(['extension_id', 'enabled', 'params']))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('aimarkdown'));
            self::$plugin = $db->setQuery($query)->loadObject() ?: null;
        }

        return self::$plugin;
    }

    public static function params(): Registry
    {
        return new Registry((string) (self::plugin()->params ?? ''));
    }

    /** the texts of the settings are the plugin's: loaded where a page of AI Markdown shows them */
    public static function loadLanguage(): void
    {
        $lang = Factory::getApplication()->getLanguage();
        $lang->load('plg_system_aimarkdown', JPATH_ADMINISTRATOR)
            || $lang->load('plg_system_aimarkdown', JPATH_PLUGINS . '/system/aimarkdown');
    }

    /** the plugin's manifest, whose <config> holds every setting */
    public static function manifest(): ?\SimpleXMLElement
    {
        if (self::$manifest === null) {
            $file = JPATH_PLUGINS . '/system/aimarkdown/aimarkdown.xml';
            $xml  = is_file($file) ? simplexml_load_file($file) : false;
            if ($xml === false || !isset($xml->config->fields)) {
                return null;
            }
            self::$manifest = $xml;
        }

        return self::$manifest;
    }

    /** the default value of every setting, from the manifest (the plugin uses the same ones when a setting was never saved) */
    public static function defaults(): array
    {
        if (self::$defaults === null) {
            self::$defaults = [];
            foreach (self::manifest()?->config->fields->fieldset ?? [] as $fieldset) {
                foreach ($fieldset->field as $field) {
                    if (isset($field['default'])) {
                        self::$defaults[(string) $field['name']] = (string) $field['default'];
                    }
                }
            }
        }

        return self::$defaults;
    }

    /**
     * The form of a page: the fieldset of the manifest under jform[params], without the "return to
     * Gridbox" field of the plugin's own form (the buttons of the page decide where to go) and with the
     * help ("?") field on every page. Null for a page that is not in the manifest.
     */
    public static function form(string $page): ?Form
    {
        $fields = self::manifest()?->config->fields;
        if (!$fields) {
            return null;
        }
        $xml  = '';
        $help = false;
        foreach ($fields->fieldset as $fieldset) {
            if ((string) $fieldset['name'] !== $page) {
                continue;
            }
            $copy = new \SimpleXMLElement($fieldset->asXML());
            foreach ($copy->xpath('field[@type="gbreturn"]') ?: [] as $field) {
                unset($field[0]);
            }
            $help = (bool) $copy->xpath('field[@type="help"]');
            // asXML() of a whole document starts with the XML declaration, which may not stand inside another document
            $xml .= preg_replace('/^<\?xml[^>]*\?>\s*/', '', (string) $copy->asXML());
        }
        if ($xml === '') {
            return null;
        }
        if (!$help) {
            $xml .= '<fieldset name="_help"><field name="help_tooltips" type="help" hiddenLabel="true" parentclass="d-none" /></fieldset>';
        }
        $form = Factory::getContainer()->get(FormFactoryInterface::class)->createForm('com_aimarkdown.page.' . $page, ['control' => 'jform']);
        $form->load('<form><fields name="params" addfieldprefix="Merserwis\\Plugin\\System\\AiMarkdown\\Field">' . $xml . '</fields></form>');

        return $form;
    }

    /**
     * The names of the settings of a form: the fields of its fieldsets, not those inside a list of rows
     * (Form::getGroup() also returns the fields of a subform), not the helper fieldset and not the
     * fields that are only a display (notes, the llms.txt and analytics panels, the settings file).
     *
     * @return list<string>
     */
    public static function fieldNames(Form $form): array
    {
        $out = [];
        foreach ($form->getXml()->xpath('//fields[@name="params"]/fieldset[not(starts-with(@name, "_"))]/field[@name]') ?: [] as $field) {
            if (!\in_array(strtolower((string) $field['type']), ['note', 'spacer', 'help', 'llms', 'analytics', 'settingsfile', 'gbreturn'], true)) {
                $out[] = (string) $field['name'];
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Saves the settings of a page: the values sent are filtered and checked by the page's form; a
     * setting of the page that was not sent (a list of checkboxes with none ticked) goes back to its
     * default, as it did when the plugin's own form replaced all its settings; the settings of the
     * other pages are kept as they are.
     *
     * @param array<string, mixed> $posted jform[params]
     *
     * @return list<string> the errors (none: saved)
     */
    public static function savePage(string $page, array $posted): array
    {
        $form = self::form($page);
        if (!$form) {
            return [Text::_('COM_AIMARKDOWN_ERROR_PAGE')];
        }
        $data  = $form->filter(['params' => $posted]);
        $valid = $form->validate($data);
        if ($valid !== true) {
            $errors = [];
            foreach ($form->getErrors() as $error) {
                $errors[] = $error instanceof \Throwable ? $error->getMessage() : (string) $error;
            }

            return $errors ?: [Text::_('COM_AIMARKDOWN_ERROR_SAVE')];
        }
        $names  = self::fieldNames($form);
        $values = array_intersect_key((array) ($data['params'] ?? []), array_flip($names));

        return self::store($values, array_diff($names, array_keys($values)));
    }

    /**
     * Writes settings into the plugin's parameters through Joomla's plugin model.
     *
     * @param array<string, mixed> $set   setting => value
     * @param list<string>         $unset settings that go back to their default
     *
     * @return list<string> the errors (none: saved)
     */
    public static function store(array $set, array $unset = []): array
    {
        // read again just before writing: another administrator may have saved another page meanwhile
        self::$plugin = false;
        $plugin       = self::plugin();
        if (!$plugin) {
            return [Text::_('COM_AIMARKDOWN_ERROR_PLUGIN')];
        }
        $params = json_decode((string) $plugin->params, true);
        $params = \is_array($params) ? $params : [];
        foreach ($unset as $key) {
            unset($params[$key]);
        }
        $params = array_replace($params, $set);

        try {
            $model = Factory::getApplication()->bootComponent('com_plugins')->getMVCFactory()
                ->createModel('Plugin', 'Administrator', ['ignore_request' => true]);
            if (!$model || !$model->save(['extension_id' => (int) $plugin->extension_id, 'params' => $params])) {
                return [($model ? (string) $model->getError() : '') ?: Text::_('COM_AIMARKDOWN_ERROR_SAVE')];
            }
        } catch (\Throwable $e) {
            return [$e->getMessage()];
        }
        self::$plugin = false;

        return [];
    }

    /** turns the plugin on (Joomla's plugin model, so the change of state is seen by other extensions) */
    public static function enable(): array
    {
        $plugin = self::plugin();
        if (!$plugin) {
            return [Text::_('COM_AIMARKDOWN_ERROR_PLUGIN')];
        }
        try {
            $model = Factory::getApplication()->bootComponent('com_plugins')->getMVCFactory()
                ->createModel('Plugin', 'Administrator', ['ignore_request' => true]);
            $pks   = [(int) $plugin->extension_id];
            if (!$model || !$model->publish($pks, 1)) {
                return [($model ? (string) $model->getError() : '') ?: Text::_('COM_AIMARKDOWN_ERROR_SAVE')];
            }
        } catch (\Throwable $e) {
            return [$e->getMessage()];
        }
        self::$plugin = false;

        return [];
    }

    /** the plugin is installed and on: its field classes (the pages are made of them) are then loaded */
    public static function pluginReady(): bool
    {
        return (int) (self::plugin()->enabled ?? 0) === 1
            && class_exists(\Merserwis\Plugin\System\AiMarkdown\Field\HelpField::class);
    }

    /** Gridbox is installed and on */
    public static function hasGridbox(): bool
    {
        return ComponentHelper::isEnabled('com_gridbox');
    }

    /** the figures of the status box: visits of AI bots in 30 days (null: no log table), /llms.txt (size in KB, null: none) */
    public static function figures(): array
    {
        $visits = null;
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            if (\in_array($db->replacePrefix('#__aimarkdown_logs'), $db->getTableList(), true)) {
                $query  = $db->createQuery()
                    ->select('COUNT(*)')
                    ->from($db->quoteName('#__aimarkdown_logs'))
                    ->where($db->quoteName('created_at') . ' >= ' . $db->quote(Factory::getDate('-30 days')->toSql()));
                $visits = (int) $db->setQuery($query)->loadResult();
            }
        } catch (\Throwable $e) {
        }
        $file = JPATH_ROOT . '/llms.txt';

        return ['visits' => $visits, 'llms' => is_file($file) ? round((int) filesize($file) / 1024, 1) : null];
    }
}
