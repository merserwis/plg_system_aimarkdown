<?php
namespace Merserwis\Plugin\System\AiMarkdown\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

/**
 * Form field rendering the LLMS.txt status, direct link, and generation button.
 */
class LlmsField extends FormField
{
    protected $type = 'Llms';

    protected function getInput(): string
    {
        $filePath = JPATH_SITE . '/llms.txt';
        $fileExists = is_file($filePath);
        $fileSize = $fileExists ? round(filesize($filePath) / 1024, 2) . ' KB' : '0 KB';
        $fileDate = $fileExists ? date('Y-m-d H:i:s', filemtime($filePath)) : 'Not generated yet';
        $fileUrl = Uri::root() . 'llms.txt';

        $token = Session::getFormToken();

        ob_start();
        ?>
        <div class="card bg-light border p-3 my-2 shadow-sm">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h6 class="mb-1 fw-bold text-dark">File Status: /llms.txt</h6>
                    <small class="text-muted">Target Path: <code><?php echo htmlspecialchars($filePath, ENT_QUOTES, 'UTF-8'); ?></code></small>
                </div>
                <div>
                    <?php if ($fileExists): ?>
                        <span class="badge bg-success">File Present</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">File Missing</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row g-2 text-center mb-3">
                <div class="col-md-6">
                    <div class="p-2 bg-white rounded border">
                        <div class="small text-muted text-uppercase">Last Modified</div>
                        <div class="fw-bold" id="llms-date"><?php echo htmlspecialchars($fileDate, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="p-2 bg-white rounded border">
                        <div class="small text-muted text-uppercase">File Size</div>
                        <div class="fw-bold" id="llms-size"><?php echo htmlspecialchars($fileSize, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center">
                <button type="button" 
                        id="btn-generate-llms" 
                        class="btn btn-primary" 
                        onclick="generateLlmsTxtNow(this);">
                    <span class="icon-refresh" aria-hidden="true"></span> Generate / Update /llms.txt Now
                </button>
                <?php if ($fileExists): ?>
                    <a href="<?php echo htmlspecialchars($fileUrl, ENT_QUOTES, 'UTF-8'); ?>" 
                       id="link-view-llms" 
                       target="_blank" 
                       rel="noopener noreferrer" 
                       class="btn btn-outline-secondary">
                        <span class="icon-eye" aria-hidden="true"></span> View /llms.txt
                    </a>
                <?php endif; ?>
            </div>
            <div id="llms-ajax-msg" class="mt-2" style="display:none;"></div>
        </div>

        <script>
        function generateLlmsTxtNow(btn) {
            btn.disabled = true;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Generating...';

            const token = '<?php echo $token; ?>';
            const url = 'index.php?aimarkdown_action=generate_llmstxt&' + token + '=1';
            const msgBox = document.getElementById('llms-ajax-msg');

            fetch(url, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => res.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = originalText;

                if (data && data.success) {
                    document.getElementById('llms-date').textContent = data.date;
                    document.getElementById('llms-size').textContent = data.size;

                    msgBox.className = 'alert alert-success mt-3 py-2 small';
                    msgBox.textContent = data.message;
                    msgBox.style.display = 'block';

                    setTimeout(() => { msgBox.style.display = 'none'; }, 4000);
                } else {
                    msgBox.className = 'alert alert-danger mt-3 py-2 small';
                    msgBox.textContent = 'Error: ' + (data.message || 'Unknown error');
                    msgBox.style.display = 'block';
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerHTML = originalText;
                msgBox.className = 'alert alert-danger mt-3 py-2 small';
                msgBox.textContent = 'Network error while generating file.';
                msgBox.style.display = 'block';
            });
        }
        </script>
        <?php
        return (string) ob_get_clean();
    }
}