<?php

/**
 * @package     Merserwis.Component
 * @subpackage  com_aimarkdown
 */

namespace Merserwis\Component\AiMarkdown\Administrator\View\Page;

\defined('_JEXEC') or die;

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Merserwis\Component\AiMarkdown\Administrator\Helper\Catalog;
use Merserwis\Component\AiMarkdown\Administrator\Helper\Settings;

/**
 * A page of settings. The fields are the plugin's own, so their help ("?"), their panels and their
 * scripts work as they did in the plugin's settings.
 */
class HtmlView extends BaseHtmlView
{
    protected string $page = '';

    protected ?Form $form = null;

    protected string $state = '';

    protected ?object $plugin = null;

    protected bool $canSave = false;

    public function display($tpl = null): void
    {
        // also when reached by another task than display (page.display): the settings are the plugin's
        if (!$this->getCurrentUser()->authorise('core.manage', 'com_plugins')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
        Settings::loadLanguage();
        $app           = Factory::getApplication();
        $this->page    = $app->getInput()->getCmd('page', '');
        $this->plugin  = Settings::plugin();
        // the pages are made of the plugin's own fields: without the plugin there is nothing to show or to save
        $this->form    = Settings::pluginReady() ? Settings::form($this->page) : null;
        $params        = Settings::params();
        $this->state   = Catalog::state(Catalog::page($this->page)['on'] ?? [], $params, Settings::defaults());
        $this->canSave = $this->getCurrentUser()->authorise('core.edit', 'com_plugins');

        if ($this->form) {
            // values typed before a save that did not pass come back
            $kept = $app->getUserState('com_aimarkdown.page.' . $this->page);
            $this->form->bind(['params' => \is_array($kept) ? array_replace($params->toArray(), $kept) : $params->toArray()]);
        }

        $wa = $this->getDocument()->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('com_aimarkdown');
        $wa->useStyle('com_aimarkdown.admin')->useScript('keepalive')->useScript('form.validate');

        $this->addToolbar();

        parent::display($tpl);
    }

    protected function addToolbar(): void
    {
        $icon = Catalog::page($this->page)['icon'] ?? 'robot';
        ToolbarHelper::title(Text::_('COM_AIMARKDOWN') . ': ' . Text::_('COM_AIMARKDOWN_PAGE_' . strtoupper($this->page)), $icon);
        $toolbar = $this->getDocument()->getToolbar();
        if ($this->canSave && $this->form) {
            $toolbar->apply('page.apply');
            $toolbar->save('page.save');
        }
        $toolbar->cancel('page.cancel', 'JTOOLBAR_CLOSE');
    }
}
