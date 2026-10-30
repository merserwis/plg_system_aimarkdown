<?php

/**
 * @package     Merserwis.Component
 * @subpackage  com_aimarkdown
 *
 * The control panel: tiles of the parts of the settings in sections, and on the side the state of
 * AI Markdown, the buttons used most and the reminder to check the site after a change.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

/** @var \Merserwis\Component\AiMarkdown\Administrator\View\Cpanel\HtmlView $this */
?>
<div class="aimc-cpanel">
    <?php if (!$this->plugin) : ?>
        <div class="alert alert-danger"><?php echo Text::_('COM_AIMARKDOWN_NO_PLUGIN'); ?></div>
    <?php elseif (!(int) $this->plugin->enabled) : ?>
        <form action="<?php echo Route::_('index.php?option=com_aimarkdown'); ?>" method="post" class="alert alert-warning d-flex flex-wrap align-items-center gap-3">
            <span class="flex-grow-1"><?php echo Text::_('COM_AIMARKDOWN_PLUGIN_OFF'); ?></span>
            <?php if ($this->canEdit) : ?>
                <button type="submit" class="btn btn-warning"><span class="icon-publish" aria-hidden="true"></span> <?php echo Text::_('COM_AIMARKDOWN_ENABLE'); ?></button>
            <?php endif; ?>
            <input type="hidden" name="task" value="page.enable">
            <?php echo HTMLHelper::_('form.token'); ?>
        </form>
    <?php endif; ?>
    <?php if (!$this->gridbox) : ?>
        <div class="alert alert-warning"><?php echo Text::_('COM_AIMARKDOWN_NO_GRIDBOX'); ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-xl-8">
            <?php foreach ($this->tiles as $group => $tiles) : ?>
                <section class="card mb-3 aimc-section aimc-group-<?php echo $this->escape($group); ?>" aria-labelledby="aimc-group-<?php echo $this->escape($group); ?>">
                    <div class="card-header">
                        <h2 class="h4 m-0" id="aimc-group-<?php echo $this->escape($group); ?>"><?php echo Text::_('COM_AIMARKDOWN_GROUP_' . strtoupper($group)); ?></h2>
                        <p class="aimc-section-desc"><?php echo Text::_('COM_AIMARKDOWN_GROUP_' . strtoupper($group) . '_DESC'); ?></p>
                    </div>
                    <div class="card-body">
                        <ul class="aimc-tiles">
                            <?php foreach ($tiles as $tile) :
                                $key   = 'COM_AIMARKDOWN_PAGE_' . strtoupper($tile['name']);
                                $state = $tile['state'] === 'on' ? Text::_('COM_AIMARKDOWN_STATE_ON') : ($tile['state'] === 'off' ? Text::_('COM_AIMARKDOWN_STATE_OFF') : '');
                                ?>
                                <li>
                                    <a class="aimc-tile aimc-tile-<?php echo $this->escape($tile['state'] ?: 'none'); ?>" href="<?php echo Route::_($tile['link']); ?>"
                                        title="<?php echo $this->escape(Text::_($key . '_DESC')); ?>">
                                        <span class="aimc-tile-icon"><span class="icon-<?php echo $this->escape($tile['icon']); ?>" aria-hidden="true"></span></span>
                                        <span class="aimc-tile-name"><?php echo Text::_($key); ?></span>
                                        <?php if ($state !== '') : ?>
                                            <span class="aimc-tile-state"><span class="aimc-dot" aria-hidden="true"></span><?php echo $state; ?></span>
                                        <?php endif; ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>

        <div class="col-xl-4">
            <section class="card mb-3">
                <h2 class="card-header h4 m-0"><?php echo Text::_('COM_AIMARKDOWN_STATUS'); ?></h2>
                <div class="card-body">
                    <p class="aimc-status-count"><?php echo Text::sprintf('COM_AIMARKDOWN_STATUS_ON', $this->on, $this->total); ?></p>
                    <dl class="aimc-status row mb-0">
                        <dt class="col-6"><?php echo Text::_('COM_AIMARKDOWN_STATUS_VERSION'); ?></dt>
                        <dd class="col-6"><?php echo $this->escape($this->version); ?></dd>
                        <dt class="col-6"><?php echo Text::_('COM_AIMARKDOWN_STATUS_PLUGIN'); ?></dt>
                        <dd class="col-6"><?php echo Text::_($this->plugin && (int) $this->plugin->enabled ? 'COM_AIMARKDOWN_STATUS_PLUGIN_ON' : 'COM_AIMARKDOWN_STATUS_PLUGIN_OFF'); ?></dd>
                        <dt class="col-6"><?php echo Text::_('COM_AIMARKDOWN_STATUS_LLMS'); ?></dt>
                        <dd class="col-6"><?php echo $this->figures['llms'] === null ? Text::_('JNO') : $this->escape($this->figures['llms'] . ' KB'); ?></dd>
                        <?php if ($this->figures['visits'] !== null) : ?>
                            <dt class="col-6"><?php echo Text::_('COM_AIMARKDOWN_STATUS_VISITS'); ?></dt>
                            <dd class="col-6"><?php echo (int) $this->figures['visits']; ?></dd>
                        <?php endif; ?>
                        <dt class="col-6"><?php echo Text::_('COM_AIMARKDOWN_STATUS_GRIDBOX'); ?></dt>
                        <dd class="col-6"><?php echo Text::_($this->gridbox ? 'JYES' : 'JNO'); ?></dd>
                    </dl>
                </div>
            </section>

            <section class="card mb-3">
                <h2 class="card-header h4 m-0"><?php echo Text::_('COM_AIMARKDOWN_ACTIONS'); ?></h2>
                <div class="card-body d-grid gap-2">
                    <a class="btn btn-outline-primary text-start" href="<?php echo Route::_('index.php?option=com_aimarkdown&view=page&page=basic'); ?>">
                        <span class="icon-cog" aria-hidden="true"></span> <?php echo Text::_('COM_AIMARKDOWN_PAGE_BASIC'); ?></a>
                    <a class="btn btn-outline-primary text-start" href="<?php echo Route::_('index.php?option=com_aimarkdown&view=page&page=llmstxt'); ?>">
                        <span class="icon-list" aria-hidden="true"></span> <?php echo Text::_('COM_AIMARKDOWN_UPDATE_LLMS'); ?></a>
                    <a class="btn btn-outline-primary text-start" href="<?php echo Route::_('index.php?option=com_aimarkdown&view=page&page=analytics'); ?>">
                        <span class="icon-chart" aria-hidden="true"></span> <?php echo Text::_('COM_AIMARKDOWN_PAGE_ANALYTICS'); ?></a>
                    <?php if ($this->figures['llms'] !== null) : ?>
                        <a class="btn btn-outline-secondary text-start" href="<?php echo $this->escape(Uri::root() . 'llms.txt'); ?>" target="_blank" rel="noopener">
                            <?php echo Text::_('COM_AIMARKDOWN_OPEN_LLMS'); ?></a>
                    <?php endif; ?>
                    <?php if ($this->gridbox) : ?>
                        <a class="btn btn-outline-secondary text-start" href="<?php echo Route::_('index.php?option=com_gridbox'); ?>">
                            <span class="icon-pencil-alt" aria-hidden="true"></span> <?php echo Text::_('COM_AIMARKDOWN_OPEN_GRIDBOX'); ?></a>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card mb-3 aimc-check">
                <h2 class="card-header h4 m-0"><span class="icon-exclamation-triangle" aria-hidden="true"></span> <?php echo Text::_('COM_AIMARKDOWN_CHECK_TITLE'); ?></h2>
                <div class="card-body"><?php echo Text::_('COM_AIMARKDOWN_CHECK_TEXT'); ?></div>
            </section>

            <p class="small text-muted"><?php echo Text::_('COM_AIMARKDOWN_SUPPORT'); ?></p>
        </div>
    </div>
</div>
