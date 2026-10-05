<?php
namespace Merserwis\Plugin\System\AiMarkdown\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;

/**
 * Settings file: export the settings of the form (with the contents of every text field) to a
 * JSON file, import such a file, or reset every setting to its default.
 */
class SettingsfileField extends FormField
{
    protected $type = 'Settingsfile';

    protected function getLabel(): string
    {
        return '';
    }

    protected function getInput(): string
    {
        $token = Session::getFormToken();
        $t     = fn (string $key): string => htmlspecialchars(Text::_('PLG_SYSTEM_AIMARKDOWN_' . $key), ENT_QUOTES, 'UTF-8');
        // texts for the script below, safe inside <script>
        $js    = fn (string $key): string => json_encode(Text::_('PLG_SYSTEM_AIMARKDOWN_' . $key), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

        ob_start();
        ?>
        <div class="card bg-light border p-3 my-2 shadow-sm" id="aimd-settings-file">
            <h6 class="mb-1 fw-bold text-dark"><?php echo $t('SETTINGS_TITLE'); ?></h6>
            <p class="small text-muted mb-3"><?php echo $t('SETTINGS_DESC'); ?></p>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-primary" data-aimd-settings="export">
                    <span class="icon-download" aria-hidden="true"></span> <?php echo $t('SETTINGS_EXPORT'); ?>
                </button>
                <label class="btn btn-secondary mb-0">
                    <span class="icon-upload" aria-hidden="true"></span> <?php echo $t('SETTINGS_IMPORT'); ?>
                    <input type="file" accept=".json,application/json" data-aimd-settings="import" hidden>
                </label>
                <button type="button" class="btn btn-outline-danger" data-aimd-settings="reset">
                    <span class="icon-undo" aria-hidden="true"></span> <?php echo $t('SETTINGS_RESET'); ?>
                </button>
            </div>
            <div id="aimd-settings-msg" class="mt-3" style="display:none;"></div>
        </div>

        <script>
        (function () {
            const T = {
                exported: <?php echo $js('SETTINGS_EXPORTED'); ?>,
                importConfirm: <?php echo $js('SETTINGS_IMPORT_CONFIRM'); ?>,
                resetConfirm: <?php echo $js('SETTINGS_RESET_CONFIRM'); ?>,
                tooBig: <?php echo $js('SETTINGS_TOO_BIG'); ?>,
                error: <?php echo $js('ERROR_PREFIX'); ?>,
                unknown: <?php echo $js('ERROR_UNKNOWN'); ?>,
                network: <?php echo $js('LLMS_NETWORK_ERROR'); ?>
            };
            const token = <?php echo json_encode($token); ?>;
            const root = document.getElementById('aimd-settings-file');
            const msg = document.getElementById('aimd-settings-msg');
            if (!root || root.dataset.ready) {
                return;
            }
            root.dataset.ready = '1';

            function show(ok, text) {
                msg.className = 'alert ' + (ok ? 'alert-success' : 'alert-danger') + ' mt-3 py-2 small';
                msg.textContent = text;
                msg.style.display = 'block';
            }

            // the token goes in the request body, not in the address (server logs)
            function post(action, fill) {
                const body = new FormData();
                body.append(token, '1');
                if (fill) {
                    fill(body);
                }
                return fetch('index.php?aimarkdown_action=' + action, {
                    method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (r) {
                    return r.json();
                }).then(function (data) {
                    if (!data || !data.success) {
                        throw new Error((data && data.message) || T.unknown);
                    }
                    return data;
                });
            }

            function fail(e) {
                show(false, T.error + (e && e.message && e.message !== 'Failed to fetch' ? e.message : T.network));
            }

            root.addEventListener('click', function (e) {
                const btn = e.target.closest('button[data-aimd-settings]');
                if (!btn) {
                    return;
                }
                if (btn.dataset.aimdSettings === 'export') {
                    // the form as it is now (also unsaved changes), plugin settings only
                    const form = root.closest('form');
                    btn.disabled = true;
                    post('settings_export', function (body) {
                        if (form) {
                            new FormData(form).forEach(function (value, key) {
                                if (key.indexOf('jform[params]') === 0) {
                                    body.append(key, value);
                                }
                            });
                        }
                    }).then(function (data) {
                        const blob = new Blob([JSON.stringify(data.data, null, 2)], { type: 'application/json' });
                        const a = document.createElement('a');
                        a.href = URL.createObjectURL(blob);
                        a.download = data.name;
                        document.body.appendChild(a);
                        a.click();
                        setTimeout(function () {
                            URL.revokeObjectURL(a.href);
                            a.remove();
                        }, 1000);
                        show(true, T.exported);
                    }).catch(fail).then(function () {
                        btn.disabled = false;
                    });
                } else if (btn.dataset.aimdSettings === 'reset') {
                    if (!window.confirm(T.resetConfirm)) {
                        return;
                    }
                    btn.disabled = true;
                    post('settings_reset').then(function (data) {
                        show(true, data.message);
                        // the form shows the defaults after a reload (the settings are saved already)
                        setTimeout(function () {
                            window.location.reload();
                        }, 900);
                    }).catch(function (err) {
                        fail(err);
                        btn.disabled = false;
                    });
                }
            });

            root.addEventListener('change', function (e) {
                const input = e.target.closest('input[data-aimd-settings="import"]');
                const file = input && input.files && input.files[0];
                if (!file) {
                    return;
                }
                input.value = '';
                if (file.size > 1048576) {
                    show(false, T.tooBig);
                    return;
                }
                if (!window.confirm(T.importConfirm)) {
                    return;
                }
                file.text().then(function (text) {
                    return post('settings_import', function (body) {
                        body.append('data', text);
                    });
                }).then(function (data) {
                    show(true, data.message);
                    setTimeout(function () {
                        window.location.reload();
                    }, 900);
                }).catch(fail);
            });
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }
}
