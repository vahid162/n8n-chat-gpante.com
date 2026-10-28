/* Local administration helpers. The appearance sample never starts a chat. */
(() => {
  'use strict';

  function shortcutUrl(value) {
    if (typeof value !== 'string' || !value || value.length > 2048 || /[\s\x00-\x20\x7f\\<>"']|%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i.test(value)) return false;
    if (value.startsWith('/') && !value.startsWith('//')) return true;
    try {
      const url = new URL(value);
      return value.startsWith('https://') && !!url.hostname && !url.username && !url.password;
    } catch (_) { return false; }
  }

  document.addEventListener('DOMContentLoaded', () => {
    for (const preview of document.querySelectorAll('.gpante-admin-preview')) {
      const defaults = {primary:'#365e28',on_primary:'#ffffff',surface:'#ffffff',text:'#263023',messages:'#f7f9f4',user:'#e9f0e2',assistant:'#ffffff',accent:'#fbbc34'};
      let initial = {};
      try { initial = JSON.parse(preview.dataset.colors); } catch (_) { /* Use the safe palette. */ }
      const colors = Object.fromEntries(Object.entries(defaults).map(([key,value]) => [key, /^#[0-9a-f]{6}$/i.test(initial?.[key]) ? initial[key] : value]));
      const validText = field => field && field.value.trim() && field.checkValidity();
      const update = () => {
        for (const sample of preview.querySelectorAll('[data-gpante-preview-text]')) {
          const field = document.getElementById('gpante-appearance-' + sample.dataset.gpantePreviewText);
          if (validText(field)) sample.textContent = field.value;
        }
        const inputLabel = document.getElementById('gpante-appearance-input_label');
        if (validText(inputLabel)) preview.querySelector('[data-gpante-preview-input]')?.setAttribute('aria-label', inputLabel.value);
        for (const key of Object.keys(defaults)) {
          const value = document.getElementById('gpante-color-' + key)?.value;
          if (/^#[0-9a-f]{6}$/i.test(value)) colors[key] = value;
          preview.style.setProperty('--gpante-preview-' + key.replaceAll('_','-'), colors[key]);
        }
        for (const sample of preview.querySelectorAll('[data-gpante-preview-link]')) {
          const index = sample.dataset.gpantePreviewLink;
          const label = document.getElementById('gpante-link-label-' + index);
          const url = document.getElementById('gpante-link-url-' + index)?.value.trim();
          sample.hidden = !validText(label) || !shortcutUrl(url);
          sample.textContent = sample.hidden ? '' : label.value;
          sample.setAttribute('title', sample.hidden ? '' : url);
        }
      };
      const form = preview.closest('form');
      form?.addEventListener('input', update);
      form?.addEventListener('change', update);
      update();
    }

    for (const button of document.querySelectorAll('[data-gpante-confirm]')) {
      button.addEventListener('click', event => {
        let accepted = false;
        try { accepted = window.confirm(button.dataset.gpanteConfirm) === true; } catch (_) { /* Cancel if a dialog is unavailable. */ }
        if (!accepted) event.preventDefault();
      });
    }

    for (const button of document.querySelectorAll('[data-gpante-copy]')) {
      button.addEventListener('click', async event => {
        event.preventDefault();
        const source = document.getElementById(button.dataset.gpanteCopy);
        const status = button.parentElement.querySelector('[data-gpante-copy-status]');
        const text = source?.type === 'password' ? '' : source?.textContent || source?.value || '';
        let copied = false;
        if (text) {
          try { await navigator.clipboard.writeText(text); copied = true; } catch (_) {
            const previous = document.activeElement;
            const field = document.createElement('textarea');
            field.value = text;
            field.setAttribute('readonly','');
            field.style.position = 'fixed';
            field.style.opacity = '0';
            document.body.append(field);
            field.select();
            try { copied = document.execCommand('copy') === true; } catch (_) { /* Report the failure without the copied content. */ }
            field.remove();
            previous?.focus();
          }
        }
        if (status) status.textContent = copied ? 'کپی شد.' : 'کپی خودکار انجام نشد؛ متن کامل را انتخاب و کپی کنید.';
      });
    }
  });
})();
