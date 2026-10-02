<?php
namespace Merserwis\Plugin\System\AiMarkdown\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;

/**
 * Custom form field rendering the AI Bot Analytics Dashboard inside Joomla plugin settings.
 */
class AnalyticsField extends FormField
{
    protected $type = 'Analytics';

    protected function getInput(): string
    {
        $app = Factory::getApplication();
        $db  = Factory::getContainer()->get(DatabaseInterface::class);

        $user = $app->getIdentity();
        if (!$user || !$user->authorise('core.edit', 'com_plugins')) {
            return '';
        }

        $t  = fn (string $key): string => htmlspecialchars(Text::_('PLG_SYSTEM_AIMARKDOWN_' . $key), ENT_QUOTES, 'UTF-8');
        $tn = fn (string $key, int $n): string => htmlspecialchars(Text::plural('PLG_SYSTEM_AIMARKDOWN_' . $key, $n), ENT_QUOTES, 'UTF-8');
        // texts for the script below, safe inside <script>
        $js = fn (string $key): string => json_encode(Text::_('PLG_SYSTEM_AIMARKDOWN_' . $key), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

        $tables    = $db->getTableList();
        $tableName = $db->replacePrefix('#__aimarkdown_logs');
        if (!in_array($tableName, $tables, true)) {
            return '<div class="alert alert-info">' . $t('ANALYTICS_NO_TABLE') . '</div>';
        }

        $displayLimit = (int) $this->form->getValue('analytics_display_limit', 'params', 10);
        if (!in_array($displayLimit, [5, 10, 15, 30], true)) {
            $displayLimit = 10;
        }

        // 4. Total general visits in the last 30 days
        // Logs are stored in UTC (Factory::getDate()); the 30-day window is computed the same way
        $since = $db->quote(Factory::getDate('-30 days')->toSql());

        $query = $db->getQuery(true)
            ->select([
                'COUNT(*) AS ' . $db->quoteName('total'),
                'COALESCE(SUM(' . $db->quoteName('is_cache_hit') . '), 0) AS ' . $db->quoteName('hits'),
                'COALESCE(SUM(CASE WHEN ' . $db->quoteName('url') . ' LIKE ' . $db->quote('%/llms.txt') . ' THEN 1 ELSE 0 END), 0) AS ' . $db->quoteName('llms'),
            ])
            ->from($db->quoteName('#__aimarkdown_logs'))
            ->where($db->quoteName('created_at') . ' >= ' . $since);
        $counters = $db->setQuery($query)->loadAssoc() ?: [];

        $totalVisits     = (int) ($counters['total'] ?? 0);
        $cacheHits       = (int) ($counters['hits'] ?? 0);
        $totalLlmsVisits = (int) ($counters['llms'] ?? 0);

        if ($totalVisits === 0) {
            return '<div class="alert alert-info my-3">
                <h5 class="alert-heading">' . $t('ANALYTICS_NO_VISITS_TITLE') . '</h5>
                <p class="mb-0">' . $t('ANALYTICS_NO_VISITS_DESC') . '</p>
            </div>';
        }

        // 5. Cache Hit Rate
        $hitRate = $totalVisits > 0 ? round(($cacheHits / $totalVisits) * 100, 1) : 0;

        // 6. Breakdown by Bot (All requests)
        $query = $db->getQuery(true)
            ->select([$db->quoteName('bot_name'), 'COUNT(*) AS ' . $db->quoteName('count')])
            ->from($db->quoteName('#__aimarkdown_logs'))
            ->where($db->quoteName('created_at') . ' >= ' . $since)
            ->group($db->quoteName('bot_name'))
            ->order($db->quoteName('count') . ' DESC');
        $db->setQuery($query);
        $botStats = $db->loadAssocList() ?: [];

        $topBot = !empty($botStats) ? $botStats[0]['bot_name'] : Text::_('PLG_SYSTEM_AIMARKDOWN_NONE');

        // 7. Top Pages Crawled
        $query = $db->getQuery(true)
            ->select([$db->quoteName('url'), 'COUNT(*) AS ' . $db->quoteName('count')])
            ->from($db->quoteName('#__aimarkdown_logs'))
            ->where($db->quoteName('created_at') . ' >= ' . $since)
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
            ->select([$db->quoteName('bot_name'), 'COUNT(*) AS ' . $db->quoteName('count')])
            ->from($db->quoteName('#__aimarkdown_logs'))
            ->where($db->quoteName('url') . ' LIKE ' . $db->quote('%/llms.txt'))
            ->where($db->quoteName('created_at') . ' >= ' . $since)
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
                    <h5 class="mb-0 fw-bold text-dark"><?php echo $t('ANALYTICS_TITLE'); ?></h5>
                    <small class="text-muted"><?php echo $tn('ANALYTICS_DISPLAY_LIMIT_N', $displayLimit); ?></small>
                </div>
                <button type="button"
                        id="btn-clear-ai-logs"
                        class="btn btn-sm btn-outline-danger"
                        onclick="clearAiMarkdownLogs(this);">
                    <span class="icon-trash" aria-hidden="true"></span> <?php echo $t('ANALYTICS_CLEAR'); ?>
                </button>
            </div>

            <!-- KPI Cards -->
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="card bg-light border-0 shadow-sm text-center p-3">
                        <div class="text-muted small text-uppercase"><?php echo $t('KPI_VISITS'); ?></div>
                        <div class="fs-2 fw-bold text-primary"><?php echo number_format($totalVisits); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-light border-0 shadow-sm text-center p-3">
                        <div class="text-muted small text-uppercase"><?php echo $t('KPI_HIT_RATE'); ?></div>
                        <div class="fs-2 fw-bold text-success"><?php echo $hitRate; ?>%</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-light border-0 shadow-sm text-center p-3">
                        <div class="text-muted small text-uppercase"><?php echo $t('KPI_UNIQUE_BOTS'); ?></div>
                        <div class="fs-2 fw-bold text-info"><?php echo count($botStats); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-light border-0 shadow-sm text-center p-3">
                        <div class="text-muted small text-uppercase"><?php echo $t('KPI_TOP_BOT'); ?></div>
                        <div class="fs-5 fw-bold text-dark mt-2 text-truncate" title="<?php echo htmlspecialchars($topBot, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($topBot, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- DEDICATED SECTION FOR /llms.txt FILE       -->
            <!-- ========================================== -->
            <div class="card border border-primary-subtle shadow-sm mb-4">
                <div class="card-header bg-primary-subtle d-flex justify-content-between align-items-center py-2">
                    <h6 class="mb-0 fw-bold text-primary">
                        <span class="icon-file-text" aria-hidden="true"></span> <?php echo $t('ANALYTICS_LLMS_TITLE'); ?>
                    </h6>
                    <span class="badge bg-primary rounded-pill"><?php echo $tn('DOWNLOADS_N', $totalLlmsVisits); ?></span>
                </div>
                <div class="card-body">
                    <?php if ($totalLlmsVisits === 0): ?>
                        <div class="text-muted small py-2">
                            <?php echo Text::_('PLG_SYSTEM_AIMARKDOWN_ANALYTICS_LLMS_NONE'); ?>
                        </div>
                    <?php else: ?>
                        <div class="row g-3">
                            <!-- Breakdown of bots fetching llms.txt -->
                            <div class="col-md-6 border-end">
                                <h6 class="small text-muted text-uppercase mb-2"><?php echo $t('ANALYTICS_LLMS_BOTS'); ?></h6>
                                <?php foreach ($llmsBotStats as $stat): 
                                    $pct = round(($stat['count'] / $totalLlmsVisits) * 100, 1);
                                ?>
                                    <div class="mb-2">
                                        <div class="d-flex justify-content-between small mb-1">
                                            <span class="fw-semibold"><?php echo htmlspecialchars($stat['bot_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span><?php echo $tn('DOWNLOADS_N', (int) $stat['count']); ?> (<?php echo $pct; ?>%)</span>
                                        </div>
                                        <div class="progress" style="height: 6px;">
                                            <div class="progress-bar bg-info" role="progressbar" style="width: <?php echo $pct; ?>%"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Latest llms.txt requests table -->
                            <div class="col-md-6">
                                <h6 class="small text-muted text-uppercase mb-2"><?php echo $t('ANALYTICS_LLMS_LATEST'); ?></h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle mb-0" style="font-size: 11px;">
                                        <thead>
                                            <tr class="text-muted">
                                                <th><?php echo $t('COL_DATE'); ?></th>
                                                <th><?php echo $t('COL_BOT'); ?></th>
                                                <th><?php echo $t('COL_IP'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recentLlmsLogs as $log): ?>
                                                <tr>
                                                    <td><?php echo HTMLHelper::_('date', $log['created_at'], 'Y-m-d H:i:s'); ?></td>
                                                    <td><strong><?php echo htmlspecialchars($log['bot_name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                                    <td class="text-muted" dir="ltr"><?php echo htmlspecialchars($log['ip_address'], ENT_QUOTES, 'UTF-8'); ?></td>
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
                        <h5 class="card-title mb-3"><?php echo $t('ANALYTICS_BREAKDOWN'); ?></h5>
                        <?php foreach ($botStats as $stat): 
                            $pct = round(($stat['count'] / $totalVisits) * 100, 1);
                        ?>
                            <div class="mb-2">
                                <div class="d-flex justify-content-between small mb-1">
                                    <span class="fw-semibold"><?php echo htmlspecialchars($stat['bot_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span><?php echo $tn('VISITS_N', (int) $stat['count']); ?> (<?php echo $pct; ?>%)</span>
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
                        <h5 class="card-title mb-3"><?php echo $tn('ANALYTICS_TOP_PAGES_N', $displayLimit); ?></h5>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($topPages as $page): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
                                    <a href="<?php echo htmlspecialchars($page['url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="text-truncate me-2 small text-decoration-none" style="max-width: 80%;" title="<?php echo htmlspecialchars($page['url'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <bdi dir="ltr"><?php echo htmlspecialchars($page['url'], ENT_QUOTES, 'UTF-8'); ?></bdi>
                                    </a>
                                    <span class="badge bg-secondary rounded-pill"><?php echo (int) $page['count']; ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Recent Logs Table -->
            <div class="card border p-3 shadow-sm">
                <h5 class="card-title mb-3"><?php echo $tn('ANALYTICS_LATEST_N', $displayLimit); ?></h5>
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-hover align-middle mb-0">
                        <thead>
                            <tr class="small text-muted">
                                <th><?php echo $t('COL_DATE'); ?></th>
                                <th><?php echo $t('COL_BOT_NAME'); ?></th>
                                <th><?php echo $t('COL_URL'); ?></th>
                                <th><?php echo $t('COL_CACHE'); ?></th>
                                <th><?php echo $t('COL_IP'); ?></th>
                            </tr>
                        </thead>
                        <tbody class="small">
                            <?php foreach ($recentLogs as $log): ?>
                                <tr>
                                    <td><?php echo HTMLHelper::_('date', $log['created_at'], 'Y-m-d H:i:s'); ?></td>
                                    <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($log['bot_name'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td class="text-truncate" style="max-width: 250px;">
                                        <a href="<?php echo htmlspecialchars($log['url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="text-decoration-none" title="<?php echo htmlspecialchars($log['url'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <bdi dir="ltr"><?php echo htmlspecialchars($log['url'], ENT_QUOTES, 'UTF-8'); ?></bdi>
                                        </a>
                                    </td>
                                    <td>
                                        <?php if ((int) $log['is_cache_hit'] === 1): ?>
                                            <span class="badge bg-success-subtle text-success">HIT</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary">MISS</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted" dir="ltr"><?php echo htmlspecialchars($log['ip_address'], ENT_QUOTES, 'UTF-8'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <script>
        function clearAiMarkdownLogs(btn) {
            const T = {
                confirm: <?php echo $js('ANALYTICS_CLEAR_CONFIRM'); ?>,
                clearing: <?php echo $js('ANALYTICS_CLEARING'); ?>,
                clear: <?php echo $js('ANALYTICS_CLEAR'); ?>,
                clearedTitle: <?php echo $js('ANALYTICS_CLEARED_TITLE'); ?>,
                clearedDesc: <?php echo $js('ANALYTICS_CLEARED_DESC'); ?>,
                error: <?php echo $js('ANALYTICS_CLEAR_ERROR'); ?>,
                unknown: <?php echo $js('ERROR_UNKNOWN'); ?>,
                network: <?php echo $js('ANALYTICS_CLEAR_NETWORK_ERROR'); ?>
            };
            const restore = () => {
                btn.disabled = false;
                btn.innerHTML = '<span class="icon-trash" aria-hidden="true"></span> ';
                btn.appendChild(document.createTextNode(T.clear));
            };
            if (!confirm(T.confirm)) {
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ';
            btn.appendChild(document.createTextNode(T.clearing));

            const token = <?php echo json_encode($token); ?>;
            // the token goes in the request body, not in the address (server logs)
            const body = new FormData();
            body.append(token, '1');

            fetch('index.php?aimarkdown_action=clear_logs', {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => res.json())
            .then(data => {
                if (data && data.success) {
                    const dashboard = document.querySelector('.ai-analytics-dashboard');
                    if (dashboard) {
                        const box = document.createElement('div');
                        box.className = 'alert alert-success my-3';
                        const h = document.createElement('h5');
                        h.className = 'alert-heading mb-1';
                        h.textContent = T.clearedTitle;
                        const p = document.createElement('p');
                        p.className = 'mb-0';
                        p.textContent = T.clearedDesc;
                        box.append(h, p);
                        dashboard.replaceChildren(box);
                    }
                } else {
                    alert(T.error + ((data && data.message) || T.unknown));
                    restore();
                }
            })
            .catch(err => {
                alert(T.network);
                restore();
            });
        }
        </script>
        <?php
        return (string) ob_get_clean();
    }
}