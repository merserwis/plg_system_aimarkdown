<?php

/**
 * Close, Save & Close (and Close after Save) of the plugin settings go to the Gridbox panel instead
 * of the list of plugins: Joomla's form controller follows a "return" address posted with the form.
 * Nothing is saved; without Gridbox the list of plugins stays the target.
 */

namespace Merserwis\Plugin\System\AiMarkdown\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Form\FormField;

final class GbreturnField extends FormField
{
    protected $type = 'Gbreturn';

    public function renderField($options = [])
    {
        if (ComponentHelper::isInstalled('com_gridbox') && ComponentHelper::isEnabled('com_gridbox')) {
            return '<input type="hidden" name="return" value="' . base64_encode('index.php?option=com_gridbox') . '">';
        }

        return '';
    }

    protected function getInput()
    {
        return '';
    }
}
