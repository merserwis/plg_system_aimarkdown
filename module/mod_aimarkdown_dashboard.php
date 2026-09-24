<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;

$db = Factory::getContainer()->get(DatabaseInterface::class);

// Sprawdź czy tabela istnieje
$tables = $db->getTableList();
$tableName = $db->replacePrefix('#__aimarkdown_logs');
if (!in_array($tableName, $tables, true)) {
    return;
}

// 1-3. Wizyty 24h / 30 dni / cache hit w jednym zapytaniu. Logi są w UTC, granice liczone w PHP
// (niezależne od strefy czasowej i silnika bazy danych)
$query = $db->getQuery(true)
    ->select([
        'COALESCE(SUM(CASE WHEN ' . $db->quoteName('created_at') . ' >= ' . $db->quote(Factory::getDate('-24 hours')->toSql()) . ' THEN 1 ELSE 0 END), 0) AS ' . $db->quoteName('d1'),
        'COUNT(*) AS ' . $db->quoteName('d30'),
        'COALESCE(SUM(' . $db->quoteName('is_cache_hit') . '), 0) AS ' . $db->quoteName('hits'),
    ])
    ->from($db->quoteName('#__aimarkdown_logs'))
    ->where($db->quoteName('created_at') . ' >= ' . $db->quote(Factory::getDate('-30 days')->toSql()));
$counters  = $db->setQuery($query)->loadAssoc() ?: [];
$visits24h = (int) ($counters['d1'] ?? 0);
$visits30d = (int) ($counters['d30'] ?? 0);
$cacheHits = (int) ($counters['hits'] ?? 0);
$hitRate = $visits30d > 0 ? round(($cacheHits / $visits30d) * 100, 1) : 0;

// 4. Ostatni bot
$query = $db->getQuery(true)
    ->select([$db->quoteName('bot_name'), $db->quoteName('created_at')])
    ->from($db->quoteName('#__aimarkdown_logs'))
    ->order($db->quoteName('created_at') . ' DESC')
    ->setLimit(1);
$db->setQuery($query);
$lastBot = $db->loadAssoc() ?: ['bot_name' => 'Brak', 'created_at' => '-'];

// Pobierz ID wtyczki do linku
$query = $db->getQuery(true)
    ->select($db->quoteName('extension_id'))
    ->from($db->quoteName('#__extensions'))
    ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
    ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
    ->where($db->quoteName('element') . ' = ' . $db->quote('aimarkdown'));
$db->setQuery($query);
$pluginId = (int) $db->loadResult();
$settingsUrl = Route::_('index.php?option=com_plugins&task=plugin.edit&extension_id=' . $pluginId);
?>

<div class="card mb-3 shadow-sm border-0">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="card-title mb-0 fw-bold text-primary">
                <span class="icon-robot" aria-hidden="true"></span> Ruch Botów AI (Markdown)
            </h5>
            <a href="<?php echo htmlspecialchars($settingsUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-primary">
                Ustawienia
            </a>
        </div>

        <div class="row g-2 text-center mb-3">
            <div class="col-4">
                <div class="p-2 bg-light rounded">
                    <div class="fs-4 fw-bold text-dark"><?php echo $visits24h; ?></div>
                    <div class="small text-muted text-uppercase" style="font-size: 10px;">Dziś (24h)</div>
                </div>
            </div>
            <div class="col-4">
                <div class="p-2 bg-light rounded">
                    <div class="fs-4 fw-bold text-primary"><?php echo $visits30d; ?></div>
                    <div class="small text-muted text-uppercase" style="font-size: 10px;">30 dni</div>
                </div>
            </div>
            <div class="col-4">
                <div class="p-2 bg-light rounded">
                    <div class="fs-4 fw-bold text-success"><?php echo $hitRate; ?>%</div>
                    <div class="small text-muted text-uppercase" style="font-size: 10px;">Cache Hit</div>
                </div>
            </div>
        </div>

        <div class="small text-muted d-flex justify-content-between border-top pt-2">
            <span>Ostatnia wizyta:</span>
            <span class="fw-semibold text-truncate ms-2" style="max-width: 60%;" title="<?php echo htmlspecialchars($lastBot['bot_name'] . ($lastBot['created_at'] !== '-' ? ' — ' . HTMLHelper::_('date', $lastBot['created_at'], 'Y-m-d H:i') : ''), ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($lastBot['bot_name'], ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </div>
    </div>
</div>