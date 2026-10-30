<?php

/**
 * @package     Merserwis.Component
 * @subpackage  com_aimarkdown
 *
 * A page of settings: the fieldset of the plugin's manifest in a card, under the description of the page.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Merserwis\Component\AiMarkdown\Administrator\View\Page\HtmlView $this */

$page = $this->page;
?>
<form action="<?php echo Route::_('index.php?option=com_aimarkdown&view=page&page=' . $page); ?>" method="post" name="adminForm" id="adminForm"
    class="form-validate aimc-page aimc-page-<?php echo $this->escape($page); ?>" data-cancel-task="page.cancel" aria-label="<?php echo $this->escape(Text::_('COM_AIMARKDOWN_PAGE_' . strtoupper($page))); ?>">

    <?php if (!$this->plugin || !(int) $this->plugin->enabled) : ?>
        <div class="alert alert-warning">
            <?php echo Text::_('COM_AIMARKDOWN_PLUGIN_OFF'); ?>
            <a href="<?php echo Route::_('index.php?option=com_aimarkdown'); ?>"><?php echo Text::_('COM_AIMARKDOWN_MENU_CPANEL'); ?></a>
        </div>
    <?php endif; ?>

    <p class="aimc-page-desc"><?php echo Text::_('COM_AIMARKDOWN_PAGE_' . strtoupper($page) . '_DESC'); ?></p>

    <?php if ($this->state === 'off') : ?>
        <div class="alert alert-info"><?php echo Text::_('COM_AIMARKDOWN_TOOL_OFF'); ?></div>
    <?php endif; ?>

    <?php if (!$this->form) : ?>
        <div class="alert alert-danger"><?php echo Text::_('COM_AIMARKDOWN_ERROR_PAGE'); ?></div>
    <?php else : ?>
        <div class="card"><div class="card-body"><?php echo $this->form->renderFieldset($page); ?></div></div>
        <?php echo $this->form->getFieldset('_help') ? $this->form->renderFieldset('_help') : ''; ?>
    <?php endif; ?>

    <input type="hidden" name="task" value="">
    <input type="hidden" name="page" value="<?php echo $this->escape($page); ?>">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
