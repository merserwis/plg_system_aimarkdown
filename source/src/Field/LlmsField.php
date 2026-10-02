<?php
namespace Merserwis\Plugin\System\AiMarkdown\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

/**
 * Form field rendering the LLMS.txt status, progress bar, link counter, and generation button.
 */
class LlmsField extends FormField
{
    protected $type = 'Llms';

    protected function getInput(): string
    {
        $filePath   = JPATH_ROOT . '/llms.txt';
        $fileExists = is_file($filePath);
        $fileSize   = $fileExists ? round(filesize($filePath) / 1024, 2) . ' KB' : '0 KB';
        $fileDate   = $fileExists ? HTMLHelper::_('date', Factory::getDate('@' . filemtime($filePath))->toSql(), 'Y-m-d H:i:s') : Text::_('PLG_SYSTEM_AIMARKDOWN_LLMS_NOT_GENERATED');
        $fileUrl    = Uri::root() . 'llms.txt';

        // Policz linki w istniejącym pliku
        $linksCount = 0;
        if ($fileExists) {
            $content = (string) file_get_contents($filePath);
            $linksCount = preg_match_all('/^- \[.*?\]\(.*?\)/m', $content);
        }

        $token = Session::getFormToken();
        $t     = fn (string $key): string => htmlspecialchars(Text::_('PLG_SYSTEM_AIMARKDOWN_' . $key), ENT_QUOTES, 'UTF-8');
        // texts for the script below, safe inside <script>
        $js    = fn (string $key): string => json_encode(Text::_('PLG_SYSTEM_AIMARKDOWN_' . $key), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

        ob_start();
        ?>
        <div class="card bg-light border p-3 my-2 shadow-sm">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h6 class="mb-1 fw-bold text-dark"><?php echo $t('LLMS_FILE_STATUS'); ?></h6>
                    <small class="text-muted"><?php echo $t('LLMS_TARGET_PATH'); ?> <code><?php echo htmlspecialchars($filePath, ENT_QUOTES, 'UTF-8'); ?></code></small>
                </div>
                <div>
                    <?php if ($fileExists): ?>
                        <span class="badge bg-success" id="llms-status-badge"><?php echo $t('LLMS_FILE_PRESENT'); ?></span>
                    <?php else: ?>
                        <span class="badge bg-secondary" id="llms-status-badge"><?php echo $t('LLMS_FILE_MISSING'); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Kafelki ze statystykami pliku -->
            <div class="row g-2 text-center mb-3">
                <div class="col-md-4">
                    <div class="p-2 bg-white rounded border">
                        <div class="small text-muted text-uppercase" style="font-size: 11px;"><?php echo $t('LLMS_LAST_MODIFIED'); ?></div>
                        <div class="fw-bold" id="llms-date"><?php echo htmlspecialchars($fileDate, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-2 bg-white rounded border">
                        <div class="small text-muted text-uppercase" style="font-size: 11px;"><?php echo $t('LLMS_FILE_SIZE'); ?></div>
                        <div class="fw-bold text-primary" id="llms-size"><?php echo htmlspecialchars($fileSize, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-2 bg-white rounded border">
                        <div class="small text-muted text-uppercase" style="font-size: 11px;"><?php echo $t('LLMS_LINKS_COUNT'); ?></div>
                        <div class="fs-6 fw-bold text-success" id="llms-links"><?php echo htmlspecialchars(Text::plural('PLG_SYSTEM_AIMARKDOWN_LLMS_LINKS_N', (int) $linksCount), ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </div>
            </div>

            <!-- Animowany pasek postępu (widoczny podczas generowania) -->
            <div id="llms-progress-wrapper" class="mb-3" style="display: none;">
                <div class="d-flex justify-content-between small text-muted mb-1">
                    <span><?php echo $t('LLMS_PROCESSING'); ?></span>
                    <span><?php echo $t('LLMS_PLEASE_WAIT'); ?></span>
                </div>
                <div class="progress" style="height: 10px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" role="progressbar" style="width: 100%;"></div>
                </div>
            </div>

            <!-- Przyciski akcji -->
            <div class="d-flex justify-content-between align-items-center">
                <button type="button" 
                        id="btn-generate-llms" 
                        class="btn btn-primary" 
                        onclick="generateLlmsTxtNow(this);">
                    <span class="icon-refresh" aria-hidden="true"></span> <?php echo $t('LLMS_GENERATE_NOW'); ?>
                </button>
                <a href="<?php echo htmlspecialchars($fileUrl, ENT_QUOTES, 'UTF-8'); ?>" 
                   id="link-view-llms" 
                   target="_blank" 
                   rel="noopener noreferrer" 
                   class="btn btn-outline-secondary"
                   style="<?php echo $fileExists ? '' : 'display: none;'; ?>">
                    <span class="icon-eye" aria-hidden="true"></span> <?php echo $t('LLMS_VIEW'); ?>
                </a>
            </div>

            <div id="llms-ajax-msg" class="mt-3" style="display:none;"></div>
        </div>

        <script>
        function generateLlmsTxtNow(btn) {
            const T = {
                generating: <?php echo $js('LLMS_GENERATING'); ?>,
                links: <?php echo $js('LLMS_LINKS_N'); ?>,
                present: <?php echo $js('LLMS_FILE_PRESENT'); ?>,
                error: <?php echo $js('ERROR_PREFIX'); ?>,
                unknown: <?php echo $js('ERROR_UNKNOWN'); ?>,
                network: <?php echo $js('LLMS_NETWORK_ERROR'); ?>
            };
            btn.disabled = true;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ';
            btn.appendChild(document.createTextNode(T.generating));

            const progressWrapper = document.getElementById('llms-progress-wrapper');
            const msgBox = document.getElementById('llms-ajax-msg');
            const viewLink = document.getElementById('link-view-llms');

            progressWrapper.style.display = 'block';
            msgBox.style.display = 'none';

            const token = <?php echo json_encode($token); ?>;
            // the token goes in the request body, not in the address (server logs)
            const body = new FormData();
            body.append(token, '1');

            fetch('index.php?aimarkdown_action=generate_llmstxt', {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => res.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = originalText;
                progressWrapper.style.display = 'none';

                if (data && data.success) {
                    document.getElementById('llms-date').textContent = data.date;
                    document.getElementById('llms-size').textContent = data.size;
                    document.getElementById('llms-links').textContent = T.links.replace('%d', String(data.links_count || 0));
                    
                    const badge = document.getElementById('llms-status-badge');
                    if (badge) {
                        badge.className = 'badge bg-success';
                        badge.textContent = T.present;
                    }

                    if (viewLink) {
                        viewLink.style.display = 'inline-block';
                    }

                    msgBox.className = 'alert alert-success mt-3 py-2 small';
                    msgBox.textContent = data.message;
                    msgBox.style.display = 'block';

                    setTimeout(() => { msgBox.style.display = 'none'; }, 5000);
                } else {
                    msgBox.className = 'alert alert-danger mt-3 py-2 small';
                    msgBox.textContent = T.error + ((data && data.message) || T.unknown);
                    msgBox.style.display = 'block';
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerHTML = originalText;
                progressWrapper.style.display = 'none';

                msgBox.className = 'alert alert-danger mt-3 py-2 small';
                msgBox.textContent = T.network;
                msgBox.style.display = 'block';
            });
        }
        </script>
        <?php
        return (string) ob_get_clean();
    }
}