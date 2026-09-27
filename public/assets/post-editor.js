(() => {
  const form = document.getElementById('post-form');
  if (!form) return;
  const kind = form.dataset.editorKind || 'post';
  const editor = window.tinymce;
  if (editor) editor.init({
    selector: '#post-content', base_url: '/assets/vendor/tinymce', suffix: '.min',
    license_key: 'gpl', height: 500, menubar: 'file edit view insert format tools table help',
    plugins: 'advlist autolink lists link image charmap preview anchor searchreplace visualblocks code fullscreen insertdatetime media table help wordcount',
    toolbar: 'undo redo | blocks | bold italic underline strikethrough | alignleft aligncenter alignright | bullist numlist blockquote | link image table | forecolor backcolor removeformat | code fullscreen',
    toolbar_mode: 'wrap', branding: false, promotion: false, browser_spellcheck: true,
    image_title: true, image_dimensions: true, image_advtab: true, image_caption: true,
    file_picker_types: 'image', file_picker_callback: callback => { pendingPicker = callback; openMedia('insert'); },
    content_style: 'body { font-family: Arial, Helvetica, sans-serif; font-size: 16px; line-height: 1.6; }',
    setup: instance => { instance.on('change keyup', () => instance.save()); }
  });
  form.addEventListener('submit', event => {
    if (editor) editor.triggerSave();
    if (event.submitter?.textContent.trim() === 'Publish') {
      const input = document.createElement('input'); input.type = 'hidden'; input.name = 'save_as'; input.value = 'publish'; form.append(input);
    }
  });
  const toggles = document.querySelectorAll('[data-toggle-panel]');
  for (const toggle of toggles) {
    const key = toggle.dataset.togglePanel;
    const panel = document.querySelector(`[data-screen-panel="${key}"]`);
    if (!panel) continue;
    toggle.checked = localStorage.getItem(`${kind}-panel-${key}`) !== 'hidden';
    panel.hidden = !toggle.checked;
    toggle.addEventListener('change', () => { panel.hidden = !toggle.checked; localStorage.setItem(`${kind}-panel-${key}`, toggle.checked ? 'shown' : 'hidden'); });
  }
  document.getElementById('preview-unsaved')?.addEventListener('click', () => alert('Save the draft to preview this post.'));
  const dialog = document.getElementById('post-media-dialog');
  const results = document.getElementById('media-results');
  const search = document.getElementById('media-search');
  const use = document.getElementById('use-media');
  const selection = document.getElementById('media-selection');
  const details = document.getElementById('media-details');
  const status = document.getElementById('media-upload-status');
  const title = document.getElementById('media-title');
  const alt = document.getElementById('media-alt');
  const width = document.getElementById('media-width');
  const height = document.getElementById('media-height');
  let mode = 'insert', selected = null, request = 0, pendingPicker = null;
  function selectMedia(item) {
    selected = item; use.disabled = false; details.hidden = false;
    selection.textContent = item.title || `Image #${item.id}`;
    title.value = item.title || ''; alt.value = item.alt || '';
    width.value = item.width || ''; height.value = item.height || '';
    document.getElementById('media-dimensions').textContent = item.width && item.height ? `Original size: ${item.width} × ${item.height} px` : '';
    document.getElementById('media-detail-preview').src = item.url;
    results.querySelectorAll('.media-tile').forEach(tile => tile.classList.toggle('selected', Number(tile.dataset.id) === Number(item.id)));
  }
  async function loadMedia() {
    const current = ++request;
    results.replaceChildren();
    results.textContent = 'Loading images…';
    try {
      const response = await fetch(`/api/admin/media?q=${encodeURIComponent(search.value.trim())}`, {credentials:'same-origin'});
      if (!response.ok) throw new Error('Media could not be loaded.');
      const data = await response.json();
      if (current !== request) return;
      results.replaceChildren();
      const items = Array.isArray(data) ? data : (data.items || data.media || []);
      if (!items.length) { results.textContent = 'No images found.'; return; }
      for (const item of items) {
        const button = document.createElement('button'); button.type = 'button'; button.className = 'media-tile'; button.dataset.id = item.id;
        const img = document.createElement('img'); img.src = item.url; img.alt = item.alt || '';
        const label = document.createElement('span'); label.textContent = item.title || `Image #${item.id}`;
        button.append(img,label);
        button.addEventListener('click', () => selectMedia(item));
        results.append(button);
      }
    } catch (error) { results.textContent = error.message; }
  }
  function openMedia(nextMode) { mode = nextMode; selected = null; use.disabled = true; selection.textContent = ''; details.hidden = true; status.textContent = ''; dialog.showModal(); loadMedia(); }
  document.querySelectorAll('[data-open-media]').forEach(button => button.addEventListener('click', () => { pendingPicker = null; openMedia(button.dataset.openMedia); }));
  search.addEventListener('input', () => { clearTimeout(search._timer); search._timer = setTimeout(loadMedia, 250); });
  document.getElementById('close-media').addEventListener('click', () => dialog.close());
  document.getElementById('media-upload-input').addEventListener('change', async event => {
    const file = event.target.files?.[0]; if (!file) return;
    status.textContent = 'Converting image to WebP and uploading…';
    try {
      const item = await window.uploadWebpImage(file, form.elements.csrf.value);
      search.value = ''; await loadMedia(); selectMedia(item);
      status.textContent = 'Image uploaded as WebP.';
    } catch (error) { status.textContent = error.message; }
    event.target.value = '';
  });
  document.getElementById('save-media-details').addEventListener('click', async () => {
    if (!selected) return;
    const data = new FormData(); data.append('csrf', form.elements.csrf.value); data.append('id', selected.id); data.append('title', title.value.trim()); data.append('alt', alt.value.trim());
    try {
      const response = await fetch('/admin/media/update', {method:'POST', body:data, credentials:'same-origin'});
      const result = await response.json(); if (!response.ok) throw new Error(result.error || 'Could not save image details.');
      selected.title = result.title; selected.alt = result.alt; selection.textContent = result.title; status.textContent = 'Image details saved.';
      const tile = results.querySelector(`.media-tile[data-id="${selected.id}"] span`); if (tile) tile.textContent = result.title;
    } catch (error) { status.textContent = error.message; }
  });
  use.addEventListener('click', () => {
    if (!selected) return;
    if (mode === 'gallery' && document.getElementById('gallery-ids')) {
      const field = document.getElementById('gallery-ids');
      const ids = field.value.split(',').filter(Boolean);
      if (!ids.includes(String(selected.id))) ids.push(String(selected.id));
      field.value = ids.join(',');
      const preview = document.getElementById('woo-gallery-preview');
      const tile = document.createElement('span'); tile.className = 'woo-gallery-item'; tile.dataset.galleryId = selected.id;
      const image = document.createElement('img'); image.src = selected.url; image.alt = selected.alt || '';
      const remove = document.createElement('button'); remove.type = 'button'; remove.dataset.removeGallery = selected.id; remove.setAttribute('aria-label', 'Remove gallery image'); remove.textContent = '×';
      tile.append(image, remove); preview.append(tile);
    } else if (mode === 'featured') {
      document.getElementById('featured-image-id').value = selected.id;
      const preview = document.getElementById('featured-image-preview'); preview.src = selected.url; preview.hidden = false;
      document.getElementById('remove-featured').hidden = false;
    } else if (pendingPicker) {
      pendingPicker(selected.url, {alt: alt.value.trim(), title: title.value.trim(), width: width.value || undefined, height: height.value || undefined}); pendingPicker = null;
    } else {
      const image = document.createElement('img'); image.src = selected.url; image.alt = alt.value.trim();
      if (width.value) image.width = Number(width.value); if (height.value) image.height = Number(height.value);
      const markup = image.outerHTML;
      const active = editor?.get('post-content');
      if (active) { active.insertContent(markup); active.save(); }
      else document.getElementById('post-content').value += markup;
    }
    dialog.close();
  });
  document.getElementById('remove-featured').addEventListener('click', event => { document.getElementById('featured-image-id').value = ''; document.getElementById('featured-image-preview').hidden = true; event.target.hidden = true; });
})();
