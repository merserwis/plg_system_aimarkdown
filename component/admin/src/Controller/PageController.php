<?php

/**
 * @package     Merserwis.Component
 * @subpackage  com_aimarkdown
 */

namespace Merserwis\Component\AiMarkdown\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Merserwis\Component\AiMarkdown\Administrator\Helper\Catalog;
use Merserwis\Component\AiMarkdown\Administrator\Helper\Settings;

/**
 * Saving a page of settings (page.apply stays on the page, page.save goes back to the control panel),
 * closing it, and turning the plugin on. The right to edit plugins is needed, as in Joomla's plugin
 * manager, and the form's token. Extends the display controller, so page.display is checked as the
 * plain display is.
 */
class PageController extends DisplayController
{
    public function __construct($config = [], $factory = null, $app = null, $input = null)
    {
        parent::__construct($config, $factory, $app, $input);
        $this->registerTask('apply', 'save');
    }

    public function save(): void
    {
        $this->checkToken();
        $page = $this->input->getCmd('page', '');
        $back = 'index.php?option=com_aimarkdown&view=page&page=' . $page;
        if (!Catalog::page($page)) {
            $this->setRedirect(Route::_('index.php?option=com_aimarkdown', false), Text::_('COM_AIMARKDOWN_ERROR_PAGE'), 'error');

            return;
        }
        $user = $this->app->getIdentity();
        if (!$user?->authorise('core.manage', 'com_plugins') || !$user->authorise('core.edit', 'com_plugins')) {
            $this->setRedirect(Route::_($back, false), Text::_('JLIB_APPLICATION_ERROR_SAVE_NOT_PERMITTED'), 'error');

            return;
        }
        Settings::loadLanguage();
        // the pages are made of the plugin's fields: with the plugin off a save would write defaults over the settings
        if (!Settings::pluginReady()) {
            $this->setRedirect(Route::_($back, false), Text::_('COM_AIMARKDOWN_PLUGIN_OFF'), 'error');

            return;
        }
        $posted = $this->input->post->get('jform', [], 'array');
        $errors = Settings::savePage($page, (array) ($posted['params'] ?? []));
        if ($errors) {
            // the values typed stay in the form
            $this->app->setUserState('com_aimarkdown.page.' . $page, $posted['params'] ?? []);
            foreach ($errors as $error) {
                $this->app->enqueueMessage($error, 'warning');
            }
            $this->setRedirect(Route::_($back, false));

            return;
        }
        $this->app->setUserState('com_aimarkdown.page.' . $page, null);
        $this->app->enqueueMessage(Text::_('COM_AIMARKDOWN_SAVED'));
        $this->setRedirect(Route::_($this->getTask() === 'apply' ? $back : $this->closeUrl(), false));
    }

    public function cancel(): void
    {
        $this->checkToken();
        $this->app->setUserState('com_aimarkdown.page.' . $this->input->getCmd('page', ''), null);
        $this->setRedirect(Route::_($this->closeUrl(), false));
    }

    /** the plugin was off: on (the control panel offers it) */
    public function enable(): void
    {
        $this->checkToken();
        $user = $this->app->getIdentity();
        if (!$user?->authorise('core.manage', 'com_plugins') || !$user->authorise('core.edit.state', 'com_plugins')) {
            $this->setRedirect(Route::_('index.php?option=com_aimarkdown', false), Text::_('JLIB_APPLICATION_ERROR_EDITSTATE_NOT_PERMITTED'), 'error');

            return;
        }
        $errors = Settings::enable();
        $this->setRedirect(Route::_('index.php?option=com_aimarkdown', false), $errors ? implode(' ', $errors) : Text::_('COM_AIMARKDOWN_ENABLED'), $errors ? 'error' : 'message');
    }

    /** where Save & Close and Close go: the control panel (it has the shortcut to Gridbox) */
    private function closeUrl(): string
    {
        return 'index.php?option=com_aimarkdown';
    }
}
