<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;

$app = Factory::getApplication();
$db  = Factory::getDbo();

$query = $db->getQuery(true)
    ->select($db->quoteName('extension_id'))
    ->from($db->quoteName('#__extensions'))
    ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
    ->where($db->quoteName('element') . ' = ' . $db->quote('aimarkdown'));
$db->setQuery($query);
$pluginId = (int) $db->loadResult();

$app->redirect('index.php?option=com_plugins&task=plugin.edit&extension_id=' . $pluginId);