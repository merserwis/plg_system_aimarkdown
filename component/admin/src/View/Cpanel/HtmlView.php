<?php

/**
 * @package     Merserwis.Component
 * @subpackage  com_aimarkdown
 */

namespace Merserwis\Component\AiMarkdown\Administrator\View\Cpanel;

\defined('_JEXEC') or die;

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Merserwis\Component\AiMarkdown\Administrator\Helper\Catalog;
use Merserwis\Component\AiMarkdown\Administrator\Helper\Settings;

/**
 * The control panel: the parts of the settings as tiles in sections (with a dot that says whether each
 * is on), and on the side the state of AI Markdown and the buttons used most.
 */
class HtmlView extends BaseHtmlView
{
    /** @var array<string, list<array>> */
    protected array $tiles = [];

    protected ?object $plugin = null;

    protected bool $gridbox = false;

    protected int $on = 0;

    protected int $total = 0;

    protected string $version = '';

    protected bool $canEdit = false;

    protected array $figures = [];

    public function display($tpl = null): void
    {
        // also when reached by another task than display (page.display): the settings are the plugin's
        if (!$this->getCurrentUser()->authorise('core.manage', 'com_plugins')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
        Settings::loadLanguage();
        $this->plugin  = Settings::plugin();
        $this->gridbox = Settings::hasGridbox();
        $this->tiles   = Catalog::tiles(Settings::params(), Settings::defaults());
        foreach ($this->tiles as $tiles) {
            foreach ($tiles as $tile) {
                if ($tile['state'] !== '') {
                    $this->total++;
                    $this->on += $tile['state'] === 'on' ? 1 : 0;
                }
            }
        }
        $this->version = (string) (Settings::manifest()?->version ?? '');
        $this->canEdit = $this->getCurrentUser()->authorise('core.edit.state', 'com_plugins');
        $this->figures = Settings::figures();

        $wa = $this->getDocument()->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('com_aimarkdown');
        $wa->useStyle('com_aimarkdown.admin');

        ToolbarHelper::title(Text::_('COM_AIMARKDOWN') . ': ' . Text::_('COM_AIMARKDOWN_MENU_CPANEL'), 'robot');

        parent::display($tpl);
    }
}
