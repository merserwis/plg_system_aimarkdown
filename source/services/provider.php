<?php
defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Merserwis\Plugin\System\AiMarkdown\Extension\AiMarkdown;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $dispatcher = $container->get(DispatcherInterface::class);
                $config     = (array) PluginHelper::getPlugin('system', 'aimarkdown');

                // Joomla 5.x expects the dispatcher as the first constructor argument; since 6.0 that form
                // is deprecated (removed in 7.0) and the dispatcher is injected with setDispatcher().
                $firstParam = (new \ReflectionMethod(CMSPlugin::class, '__construct'))->getParameters()[0] ?? null;
                $legacyCtor = $firstParam && (string) $firstParam->getType() === DispatcherInterface::class;

                $plugin = $legacyCtor ? new AiMarkdown($dispatcher, $config) : new AiMarkdown($config);
                $plugin->setDispatcher($dispatcher);
                $plugin->setApplication(Factory::getApplication());
                $plugin->setDatabase($container->get(DatabaseInterface::class));

                return $plugin;
            }
        );
    }
};
