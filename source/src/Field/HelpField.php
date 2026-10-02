<?php
namespace Merserwis\Plugin\System\AiMarkdown\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;

/**
 * Invisible field: the "?" help tooltips beside the option names of the plugin settings. The text
 * of each option's description sits next to its "?" and is shown by CSS (hover, keyboard focus) or
 * by a click, so no administrator template can move or hide it.
 */
class HelpField extends FormField
{
    protected $type = 'Help';

    protected function getLabel()
    {
        return '';
    }

    protected function getInput()
    {
        $wa = Factory::getApplication()->getDocument()->getWebAssetManager();
        $wa->addInlineStyle(<<<'CSS'
/* help tooltips, shown by CSS next to their "?" (same as Better Search and Better Categories) */
.bs-help-wrap{position:relative;display:inline-block;vertical-align:middle;margin-inline-start:.35rem;line-height:1}
.bs-help{display:inline-flex;align-items:center;justify-content:center;width:1.15rem;height:1.15rem;margin:0;padding:0;border:1px solid var(--template-bg-dark-30, #adb5bd);border-radius:50%;background:transparent;color:var(--template-bg-dark-60, #6c757d);font-size:.72rem;font-weight:700;line-height:1;cursor:help}
.bs-help:hover,.bs-help:focus-visible,.bs-help-wrap.is-open .bs-help{border-color:var(--template-link-color, #2a69b8);color:var(--template-link-color, #2a69b8);outline:0}
.bs-help-tip{display:none;position:absolute;z-index:1100;inset-inline-start:-.5rem;top:calc(100% + 6px);width:22rem!important;max-width:calc(100vw - 2rem)!important;min-width:0!important;box-sizing:border-box;padding:.55rem .7rem;border-radius:.375rem;background:#1f2328;color:#fff;font-size:.82rem;font-weight:400;line-height:1.45;text-align:start;white-space:normal;box-shadow:0 .5rem 1.25rem rgba(0,0,0,.25)}
.bs-help-wrap:hover .bs-help-tip,.bs-help-wrap:focus-within .bs-help-tip,.bs-help-wrap.is-open .bs-help-tip{display:block}
.bs-help-tip p{margin:0 0 .35rem}.bs-help-tip p:last-child{margin:0}
.bs-help-tip a{color:#9ec5fe}
.bs-help-tip code{color:#ffd8a8}
CSS);
        $label = json_encode(Text::_('PLG_SYSTEM_AIMARKDOWN_HELP'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        $wa->addInlineScript('(() => {' . "\n" . <<<'JS'
  // ---------------------------------------------------------------- help tooltips ("?" beside the option names)

  // The text sits next to its "?" and is shown by CSS (hover, keyboard focus) or by a click
  // (class is-open): no positioning script, so no administrator template can push it away.
  function initHelp(form, label) {
    let n = 0;
    const closeAll = (except) => {
      form.querySelectorAll('.bs-help-wrap.is-open').forEach((w) => {
        if (w !== except) {
          w.classList.remove('is-open');
          w.querySelector('.bs-help').setAttribute('aria-expanded', 'false');
        }
      });
    };
    const add = (scope) => {
      scope.querySelectorAll('.control-group').forEach((g) => {
        const head = g.querySelector('.control-label');
        const desc = g.querySelector('[id$="-desc"]');
        if (!head || !desc || !desc.textContent.trim() || head.querySelector('.bs-help-wrap')) return;
        const wrap = document.createElement('span');
        wrap.className = 'bs-help-wrap';
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'bs-help';
        b.textContent = '?';
        b.setAttribute('aria-label', label);
        b.setAttribute('aria-expanded', 'false');
        const tip = document.createElement('span');
        tip.className = 'bs-help-tip';
        tip.id = 'bs-help-tip-' + (++n);
        tip.innerHTML = (desc.querySelector('.form-text') || desc).innerHTML;
        b.setAttribute('aria-describedby', tip.id);
        wrap.append(b, tip);
        head.appendChild(wrap);
      });
    };
    form.addEventListener('click', (e) => {
      const b = e.target.closest && e.target.closest('.bs-help');
      if (!b) {
        if (!(e.target.closest && e.target.closest('.bs-help-tip'))) closeAll(null);
        return;
      }
      e.preventDefault();
      e.stopPropagation();
      const wrap = b.parentNode;
      const open = !wrap.classList.contains('is-open');
      closeAll(wrap);
      wrap.classList.toggle('is-open', open);
      b.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('keydown', (e) => {
      if (e.key !== 'Escape') return;
      closeAll(null);
      if (document.activeElement && document.activeElement.classList.contains('bs-help')) document.activeElement.blur();
    });
    add(form);
    document.addEventListener('subform-row-add', (e) => add((e.detail && e.detail.row) || e.target));
  }
JS
            . "\n  document.addEventListener('DOMContentLoaded', () => {\n    const form = document.getElementById('style-form') || document.querySelector('form[name=\"adminForm\"]');\n    if (form) initHelp(form, " . $label . ");\n  });\n})();");

        return '';
    }
}
