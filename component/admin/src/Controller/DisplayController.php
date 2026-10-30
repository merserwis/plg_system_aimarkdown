<?php

/**
 * @package     Merserwis.Component
 * @subpackage  com_aimarkdown
 */

namespace Merserwis\Component\AiMarkdown\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Merserwis\Component\AiMarkdown\Administrator\Helper\Catalog;

/**
 * The pages of AI Markdown: the control panel (default) and a page of settings (view=page&page=…).
 * Who may manage the plugins may see them (the settings are the plugin's).
 */
class DisplayController extends BaseController
{
    protected $default_view = 'cpanel';

    public function display($cachable = false, $urlparams = [])
    {
        if (!$this->app->getIdentity()?->authorise('core.manage', 'com_plugins')) {
            $this->app->enqueueMessage(Text::_('JERROR_ALERTNOAUTHOR'), 'error');
            $this->setRedirect(Route::_('index.php', false));

            return $this;
        }
        $view = $this->input->getCmd('view', $this->default_view);
        if (!\in_array($view, ['cpanel', 'page'], true)) {
            $this->input->set('view', $this->default_view);
        }
        if ($view === 'page' && !Catalog::page($this->input->getCmd('page', ''))) {
            $this->setRedirect(Route::_('index.php?option=com_aimarkdown', false), Text::_('COM_AIMARKDOWN_ERROR_PAGE'), 'error');

            return $this;
        }

        return parent::display(false, []);
    }
}
