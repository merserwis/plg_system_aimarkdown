<?php
namespace Merserwis\Plugin\System\AiMarkdown\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

/**
 * Custom form field rendering the AI Bot Analytics Dashboard inside Joomla plugin settings.
 */
class AnalyticsField extends FormField
{
    protected $type = 'Analytics';

    protected function getInput(): string
    {
        $app = Factory::getApplication();
        $db  = Factory::getDbo();

        // Security check
        if (!$app->getIdentity()->authorise('core.edit', 'com_plugins')) {
            return '';
        }

        // 1. Verify database table exists
        $tables = $db->getTableList();
        $tableName = $db->replacePrefix('#__aimarkdown_logs');
        if (!in_array($tableName, $tables, true)) {
            return '<div class="alert alert-info">Analytics table not yet created. Re-save or re-install plugin to initialize.</div>';
        }

        $extensionId = (int) $app->input->get('extension_id', 0);

        // 2. Handle Clear Statistics Action (CSRF Protected)
        if ($app->input->get('action') === 'clear_ai_logs' && Session::checkToken('get')) {
            try {
                $db->setQuery('TRUNCATE TABLE ' . $db->quoteName('#__aimarkdown_logs'))->execute();
            } catch (\Throwable $e) {
                $db->setQuery('DELETE FROM ' . $db->quoteName('#__aimarkdown_logs'))->execute();
            }

            $app->enqueueMessage('AI visit statistics have been cleared successfully.', 'message');
            $app->redirect(Route::_('index.php?option=com_plugins&task=plugin.edit&extension_id=' . $extensionId, false));
        }

        // 3. Determine display limit (5, 10, 15, 30) from plugin params
        $displayLimit = (int) $this->form->getValue('analytics_display_limit', 'params', 10);
        if (!in_array($displayLimit, [5, 10, 15, 30], true)) {
            $displayLimit = 10;
        }

        // 4. Total general visits in the last 30 days
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__aimarkdown_logs'))
            ->where($db->quoteName('created_at') . ' >= DATE_SUB(NOW(), INTERVAL 30 DAY)');
        $db->setQuery($query);
        $totalVisits = (int) $db->loadResult();

        $clearUrl = Route::_('index.php?option=com_plugins&task=plugin.edit&extension_id=' . $extensionId . '&action=clear_ai_logs&' . Session::getFormToken() . '=1');

        if ($totalVisits === 0) {
            return '<div class="alert alert-info my-3">
                <h5 class="alert-heading">No AI visits recorded yet</h5>
                <p class="mb-0">As soon as crawlers like SearchGPT, ClaudeBot, or Perplexity visit your site or query /llms.txt, detailed statistics will appear right here.</p>
            </div>';
        }

        // 5. Cache Hit Rate
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__aimarkdown_logs'))
            ->where($db->quoteName('is_cache_hit') . ' = 1')
            ->where($db->quoteName('created_at') . ' >= DATE_SUB(NOW(), INTERVAL 30 DAY)');
        $db->setQuery($query);
        $cacheHits = (int) $db->loadResult();
        $hitRate = $totalVisits > 0 ? round(($cacheHits / $totalVisits) * 100, 1) : 0;

        // 6. Breakdown by Bot (All requests)
        $query = $db->getQuery(true)
            ->select([$db->quoteName('bot_name'), 'COUNT(*) AS ' . $db->quoteName('count')])
            ->from($db->quoteName('#__aimarkdown_logs'))
            ->where($db->quoteName('created_at') . ' >= DATE_SUB(NOW(), INTERVAL 30 DAY)')
            ->group($db->quoteName('bot_name'))
            ->order($db->quoteName('count') . ' DESC');
        $db->setQuery($query);
        $botStats = $db->loadAssocList() ?: [];

        $topBot = !empty($botStats) ? $botStats[0]['bot_name'] : 'None';

        // 7. Top Pages Crawled
        $query = $db->getQuery(true)
            ->select([$db->quoteName('url'), 'COUNT(*) AS ' . $db->quoteName('count')])
            ->from($db->quoteName('#__aimarkdown_logs'))
            ->where($db->quoteName('created_at') . ' >= DATE_SUB(NOW(), INTERVAL 30 DAY)')
            ->group($db->quoteName('url'))
            ->order($db->quoteName('count') . ' DESC')
            ->setLimit($displayLimit);
        $db->setQuery($query);
        $topPages = $db->loadAssocList() ?: [];

        // 8. Recent General Visits
        $query = $db->getQuery(true)
            ->select(['bot_name', 'url', 'is_cache_hit', 'ip_address', 'created_at'])
            ->from($db->quoteName('#__aimarkdown_logs'))
            ->order($db->quoteName('created_at') . ' DESC')
            ->setLimit($displayLimit);
        $db->setQuery($query);
        $recentLogs = $db->loadAssocList() ?: [];

        // 9. DEDYKOWANA ANALITYKA DLA /llms.txt
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__aimarkdown_logs'))
            ->where($db->quoteName('url') . ' LIKE ' . $db->quote('%/llms.txt'))
            ->where($db->quoteName('created_at') . ' >= DATE_SUB(NOW(), INTERVAL 30 DAY)');
        $db->setQuery($query);
        $totalLlmsVisits = (int) $db->loadResult();

        $query = $db->getQuery(true)
            ->select([$db->quoteName('bot_name'), 'COUNT(*) AS ' . $db->quoteName('count')])
            ->from($db->quoteName('#__aimarkdown_logs'))
            ->where($db->quoteName('url') . ' LIKE ' . $db->quote('%/llms.txt'))
            ->where($db->quoteName('created_at') . ' >= DATE_SUB(NOW(), INTERVAL 30 DAY)')
            ->group($db->quoteName('bot_name'))
            ->order($db->quoteName('count') . ' DESC');
        $db->setQuery($query);
        $llmsBotStats = $db->loadAssocList() ?: [];

        $query = $db->getQuery(true)
            ->select(['bot_name', 'ip_address', 'created_at'])
            ->from($db->quoteName('#__aimarkdown_logs'))
            ->where($db->quoteName('url') . ' LIKE ' . $db->quote('%/llms.txt'))
            ->order($db->quoteName('created_at') . ' DESC')
            ->setLimit($displayLimit);
        $db->setQuery($query);
        $recentLlmsLogs = $db->loadAssocList() ?: [];

        $token = Session::getFormToken();

        ob_start();
        ?>
        <div class="ai-analytics-dashboard my-3">
            <!-- Header bar with AJAX Clear Statistics Button -->
            <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                <div>
                    <h5 class="mb-0 fw-bold text-dark">AI Crawler Activity (Last 30 Days)</h5>
                    <small class="text-muted">Displaying up to <?php echo $displayLimit; ?> items per section</small>
                </div>
                <button type="button"
                        id="btn-clear-ai-logs"
                        class="btn btn-sm btn-outline-danger"
                        onclick="clearAiMarkdownLogs(this);">
                    <span class="icon-trash" aria-hidden="true"></span> Clear Statistics
                </button>
            </div>

            <!-- KPI Cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="card bg-light border-0 shadow-sm text-center p-3">
                        <div class="text-muted small text-uppercase">30-Day AI Visits</div>
                        <div class="fs-2 fw-bold text-primary"><?php echo number_format($totalVisits); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-light border-0 shadow-sm text-center p-3">
                        <div class="text-muted small text-uppercase">Cache Hit Rate</div>
                        <div class="fs-2 fw-bold text-success"><?php echo $hitRate; ?>%</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-light border-0 shadow-sm text-center p-3">
                        <div class="text-muted small text-uppercase">Unique AI Bots</div>
                        <div class="fs-2 fw-bold text-info"><?php echo count($botStats); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-light border-0 shadow-sm text-center p-3">
                        <div class="text-muted small text-uppercase">Most Active Bot</div>
                        <div class="fs-5 fw-bold text-dark mt-2 text-truncate" title="<?php echo htmlspecialchars($topBot, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($topBot, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- DEDYKOWANA SEKCJA DLA PLIKU /llms.txt       -->
            <!-- ========================================== -->
            <div class="card border border-primary-subtle shadow-sm mb-4">
                <div class="card-header bg-primary-subtle d-flex justify-content-between align-items-center py-2">
                    <h6 class="mb-0 fw-bold text-primary">
                        <span class="icon-file-text" aria-hidden="true"></span> Aktywność botów na pliku /llms.txt (Ostatnie 30 dni)
                    </h6>
                    <span class="badge bg-primary rounded-pill"><?php echo $totalLlmsVisits; ?> pobrań</span>
                </div>
                <div class="card-body">
                    <?php if ($totalLlmsVisits === 0): ?>
                        <div class="text-muted small py-2">
                            Brak zarejestrowanych wywołań <code>/llms.txt</code> w ciągu ostatnich 30 dni. Gdy modele AI (np. ClaudeBot, GPTBot) pobiorą plik mapy serwisu, szczegółowe zestawienie pojawi się w tym miejscu.
                        </div>
                    <?php else: ?>
                        <div class="row g-3">
                            <!-- Rozbicie na boty pobierające llms.txt -->
                            <div class="col-md-6 border-end">
                                <h6 class="small text-muted text-uppercase mb-2">Boty pobierające /llms.txt:</h6>
                                <?php foreach ($llmsBotStats as $stat): 
                                    $pct = round(($stat['count'] / $totalLlmsVisits) * 100, 1);
                                ?>
                                    <div class="mb-2">
                                        <div class="d-flex justify-content-between small mb-1">
                                            <span class="fw-semibold"><?php echo htmlspecialchars($stat['bot_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span><?php echo $stat['count']; ?> pobrań (<?php echo $pct; ?>%)</span>
                                        </div>
                                        <div class="progress" style="height: 6px;">
                                            <div class="progress-bar bg-info" role="progressbar" style="width: <?php echo $pct; ?>%"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Ostatnie pobrania llms.txt -->
                            <div class="col-md-6">
                                <h6 class="small text-muted text-uppercase mb-2">Ostatnie żądania /llms.txt:</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle mb-0" style="font-size: 11px;">
                                        <thead>
                                            <tr class="text-muted">
                                                <th>Data</th>
                                                <th>Bot AI</th>
                                                <th>Adres IP</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recentLlmsLogs as $log): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($log['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td><strong><?php echo htmlspecialchars($log['bot_name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                                    <td class="text-muted"><?php echo htmlspecialchars($log['ip_address'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Bot Distribution & Top Pages (General) -->
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="card border p-3 h-100 shadow-sm">
                        <h5 class="card-title mb-3">AI Bot Activity Breakdown (All Pages)</h5>
                        <?php foreach ($botStats as $stat): 
                            $pct = round(($stat['count'] / $totalVisits) * 100, 1);
                        ?>
                            <div class="mb-2">
                                <div class="d-flex justify-content-between small mb-1">
                                    <span class="fw-semibold"><?php echo htmlspecialchars($stat['bot_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span><?php echo $stat['count']; ?> visits (<?php echo $pct; ?>%)</span>
                                </div>
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo $pct; ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card border p-3 h-100 shadow-sm">
                        <h5 class="card-title mb-3">Top <?php echo $displayLimit; ?> Pages Crawled by AI</h5>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($topPages as $page): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
                                    <a href="<?php echo htmlspecialchars($page['url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="text-truncate me-2 small text-decoration-none" style="max-width: 80%;" title="<?php echo htmlspecialchars($page['url']); ?>">
                                        <?php echo htmlspecialchars($page['url']); ?>
                                    </a>
                                    <span class="badge bg-secondary rounded-pill"><?php echo $page['count']; ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Recent Logs Table -->
            <div class="card border p-3 shadow-sm">
                <h5 class="card-title mb-3">Latest <?php echo $displayLimit; ?> AI Requests</h5>
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-hover align-middle mb-0">
                        <thead>
                            <tr class="small text-muted">
                                <th>Date &amp; Time</th>
                                <th>Bot Name</th>
                                <th>URL</th>
                                <th>Cache</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody class="small">
                            <?php foreach ($recentLogs as $log): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($log['created_at']); ?></td>
                                    <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($log['bot_name']); ?></span></td>
                                    <td class="text-truncate" style="max-width: 250px;">
                                        <a href="<?php echo htmlspecialchars($log['url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="text-decoration-none" title="<?php echo htmlspecialchars($log['url']); ?>">
                                            <?php echo htmlspecialchars($log['url']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?php if ((int) $log['is_cache_hit'] === 1): ?>
                                            <span class="badge bg-success-subtle text-success">HIT</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary">MISS</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted"><?php echo htmlspecialchars($log['ip_address']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <script>
        function clearAiMarkdownLogs(btn) {
            if (!confirm('Are you sure you want to permanently clear all AI visit logs?')) {
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Clearing...';

            const token = '<?php echo $token; ?>';
            const url = 'index.php?aimarkdown_action=clear_logs&' + token + '=1';

            fetch(url, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => res.json())
            .then(data => {
                if (data && data.success) {
                    const dashboard = document.querySelector('.ai-analytics-dashboard');
                    if (dashboard) {
                        dashboard.innerHTML = `
                            <div class="alert alert-success my-3">
                                <h5 class="alert-heading mb-1">Statistics Cleared</h5>
                                <p class="mb-0">All AI crawler logs have been deleted successfully.</p>
                            </div>
                        `;
                    }
                } else {
                    alert('Error clearing statistics: ' + (data.message || 'Unknown error'));
                    btn.disabled = false;
                    btn.innerHTML = '<span class="icon-trash" aria-hidden="true"></span> Clear Statistics';
                }
            })
            .catch(err => {
                alert('Network error while clearing statistics.');
                btn.disabled = false;
                btn.innerHTML = '<span class="icon-trash" aria-hidden="true"></span> Clear Statistics';
            });
        }
        </script>
        <?php
        return (string) ob_get_clean();
    }
}