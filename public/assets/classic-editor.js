'use strict';
document.querySelectorAll('[data-classic-editor]').forEach(editor => {
  const visual = editor.querySelector('.classic-visual');
  const source = editor.querySelector('.classic-source');
  const toolbar = editor.querySelector('.classic-toolbar');
  const tabs = [...editor.querySelectorAll('[data-editor-tab]')];
  let mode = 'visual';
  visual.innerHTML = source.value;

  function selectMode(next) {
    if (next === mode) return;
    if (next === 'text') source.value = visual.innerHTML;
    else visual.innerHTML = source.value;
    mode = next;
    visual.hidden = next !== 'visual';
    source.hidden = next !== 'text';
    toolbar.hidden = next !== 'visual';
    tabs.forEach(button => button.classList.toggle('active', button.dataset.editorTab === next));
    (next === 'visual' ? visual : source).focus();
  }

  tabs.forEach(button => button.addEventListener('click', () => selectMode(button.dataset.editorTab)));
  toolbar.addEventListener('mousedown', event => {
    if (event.target.closest('button')) event.preventDefault();
  });
  toolbar.querySelectorAll('[data-command]').forEach(button => button.addEventListener('click', () => {
    visual.focus();
    document.execCommand(button.dataset.command, false);
  }));
  toolbar.querySelector('[data-format-block]').addEventListener('change', event => {
    visual.focus();
    document.execCommand('formatBlock', false, event.target.value);
  });
  toolbar.querySelector('[data-link]').addEventListener('click', () => {
    const url = prompt('Link URL (https://)');
    if (!url) return;
    try {
      const parsed = new URL(url);
      if (parsed.protocol !== 'https:' && parsed.protocol !== 'http:') throw new Error('Invalid URL');
      visual.focus();
      document.execCommand('createLink', false, parsed.href);
    } catch { alert('Enter a valid http or https URL.'); }
  });
  editor.closest('form').addEventListener('submit', () => {
    if (mode === 'visual') source.value = visual.innerHTML;
  });
});
