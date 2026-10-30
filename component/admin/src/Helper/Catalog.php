<?php

/**
 * @package     Merserwis.Component
 * @subpackage  com_aimarkdown
 *
 * The map of AI Markdown's administrator: which page shows which part of the settings, in which section
 * of the control panel, with which icon, and which switch says that it is on. The settings themselves
 * are the fieldsets of the plugin's manifest (plg_system_aimarkdown/aimarkdown.xml): a page is one
 * fieldset, named like it.
 */

namespace Merserwis\Component\AiMarkdown\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\Registry\Registry;

final class Catalog
{
    /** the sections of the control panel, in order */
    public const GROUPS = ['markdown', 'llms', 'insights', 'config'];

    /**
     * The pages: group (section of the control panel), icon (Joomla's icon-*), on (the switches that say
     * the part works, any of them; none: it is always there and shows no dot).
     */
    public const PAGES = [
        'basic'     => ['group' => 'markdown', 'icon' => 'code',     'on' => []],
        'merchant'  => ['group' => 'markdown', 'icon' => 'cart',     'on' => ['enable_merchant_context']],
        'llmstxt'   => ['group' => 'llms',     'icon' => 'list',     'on' => ['enable_llmstxt']],
        'texts'     => ['group' => 'llms',     'icon' => 'edit',     'on' => []],
        'analytics' => ['group' => 'insights', 'icon' => 'chart',    'on' => ['enable_analytics']],
        'backup'    => ['group' => 'config',   'icon' => 'download', 'on' => []],
    ];

    /** the page of a name, or null */
    public static function page(string $name): ?array
    {
        return self::PAGES[$name] ?? null;
    }

    /** a setting that is on: anything but empty, 0, off or none */
    public static function isOn(Registry $params, array $defaults, string $key): bool
    {
        $value = $params->get($key, $defaults[$key] ?? '');
        if (\is_array($value) || \is_object($value)) {
            return (bool) $value;
        }

        return !\in_array(strtolower(trim((string) $value)), ['', '0', 'off', 'none', 'no'], true);
    }

    /** 'on' when any of the switches is on, 'off' when none is, '' for a page without switches */
    public static function state(array $keys, Registry $params, array $defaults): string
    {
        if (!$keys) {
            return '';
        }
        foreach ($keys as $key) {
            if (self::isOn($params, $defaults, $key)) {
                return 'on';
            }
        }

        return 'off';
    }

    /**
     * The tiles of the control panel: group => [[name, icon, state, link], …].
     *
     * @return array<string, list<array{name: string, icon: string, state: string, link: string}>>
     */
    public static function tiles(Registry $params, array $defaults): array
    {
        $groups = array_fill_keys(self::GROUPS, []);
        foreach (self::PAGES as $name => $page) {
            $groups[$page['group']][] = ['name' => $name, 'icon' => $page['icon'], 'state' => self::state($page['on'], $params, $defaults),
                'link' => 'index.php?option=com_aimarkdown&view=page&page=' . $name];
        }

        return array_filter($groups);
    }
}
