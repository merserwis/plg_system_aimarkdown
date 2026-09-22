<?php
defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Merserwis\Plugin\System\AiMarkdown\Extension\AiMarkdown;

/**
 * Service provider for the AiMarkdown plugin.
 * Native Dependency Injection container integration for Joomla 6.
 */
return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $dispatcher = $container->get(DispatcherInterface::class);
                $plugin     = new AiMarkdown(
                    $dispatcher,
                    (array) PluginHelper::getPlugin('system', 'aimarkdown')
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};