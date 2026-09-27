(() => {
  const page = document.querySelector('.media-page'); if (!page) return;
  const uploadPanel = document.getElementById('media-upload-panel');
  const uploadForm = document.getElementById('media-page-upload');
  const uploadStatus = document.getElementById('media-page-status');
  const fileInput = uploadForm.elements.image;
  const openUpload = () => { uploadPanel.hidden = false; uploadPanel.scrollIntoView({behavior:'smooth',block:'start'}); };
  document.getElementById('open-media-upload').addEventListener('click', () => { uploadPanel.hidden ? openUpload() : uploadPanel.hidden = true; });
  if (location.hash === '#media-page-upload') openUpload();
  async function upload(files) {
    if (!files?.length) return;
    const button = uploadForm.querySelector('button[type=submit]'); button.disabled = true;
    let completed = 0;
    for (const file of files) {
      uploadStatus.textContent = `Converting and uploading ${completed + 1} of ${files.length}: ${file.name}`;
      try { await window.uploadWebpImage(file, uploadForm.elements.csrf.value); completed++; }
      catch (error) { uploadStatus.textContent = `${completed} uploaded. ${file.name}: ${error.message}`; button.disabled = false; return; }
    }
    uploadStatus.textContent = `${completed} image${completed === 1 ? '' : 's'} uploaded as WebP.`;
    location.reload();
  }
  uploadForm.addEventListener('submit', event => { event.preventDefault(); upload([...fileInput.files]); });
  const zone = document.getElementById('media-drop-zone');
  zone.addEventListener('dragover', event => { event.preventDefault(); zone.classList.add('dragging'); });
  zone.addEventListener('dragleave', () => zone.classList.remove('dragging'));
  zone.addEventListener('drop', event => { event.preventDefault(); zone.classList.remove('dragging'); upload([...event.dataTransfer.files]); });
  const items = document.getElementById('media-items');
  const switches = document.querySelectorAll('[data-media-view]');
  function setView(view) { items.classList.toggle('media-list', view === 'list'); switches.forEach(button => button.classList.toggle('active', button.dataset.mediaView === view)); localStorage.setItem('media-library-view', view); }
  setView(localStorage.getItem('media-library-view') === 'list' ? 'list' : 'grid');
  switches.forEach(button => button.addEventListener('click', () => setView(button.dataset.mediaView)));
  document.getElementById('media-per-page').addEventListener('change', event => { const url = new URL(location.href); url.searchParams.set('per_page', event.target.value); url.searchParams.delete('page'); location.href = url.toString(); });
  const inspector = document.getElementById('media-inspector');
  const detailsForm = document.getElementById('media-details-form');
  document.querySelectorAll('.media-card').forEach(card => card.addEventListener('click', () => {
    document.querySelectorAll('.media-card.selected').forEach(item => item.classList.remove('selected'));
    card.classList.add('selected'); inspector.hidden = false;
    const data = card.dataset;
    detailsForm.elements.id.value = data.id; detailsForm.elements.title.value = data.title; detailsForm.elements.alt.value = data.alt;
    const preview = document.getElementById('media-inspector-preview'); preview.replaceChildren();
    if (data.url) { const img = document.createElement('img'); img.src = data.url; img.alt = data.alt || ''; preview.append(img); }
    else preview.textContent = 'File preview unavailable';
    document.getElementById('media-inspector-file').textContent = data.file || `Attachment #${data.id}`;
    document.getElementById('media-inspector-date').textContent = data.date;
    document.getElementById('media-inspector-type').textContent = data.mime;
    document.getElementById('media-inspector-dimensions').textContent = data.width && data.height ? `${data.width} × ${data.height} px` : '—';
    const open = document.getElementById('media-open-file'); open.hidden = !data.url; if (data.url) open.href = data.url;
    detailsForm.hidden = !data.url;
    document.getElementById('media-details-status').textContent = '';
    if (matchMedia('(max-width:900px)').matches) inspector.scrollIntoView({behavior:'smooth',block:'start'});
  }));
  document.getElementById('close-media-details').addEventListener('click', () => { inspector.hidden = true; document.querySelectorAll('.media-card.selected').forEach(item => item.classList.remove('selected')); });
  detailsForm.addEventListener('submit', async event => {
    event.preventDefault();
    const status = document.getElementById('media-details-status'); status.textContent = 'Saving…';
    try {
      const response = await fetch('/admin/media/update', {method:'POST',body:new FormData(detailsForm),credentials:'same-origin'});
      const data = await response.json(); if (!response.ok) throw new Error(data.error || 'Could not save details.');
      const card = document.querySelector('.media-card.selected'); card.dataset.title = data.title; card.dataset.alt = data.alt;
      card.querySelector('.media-card-title').textContent = data.title;
      const image = inspector.querySelector('img'); if (image) image.alt = data.alt;
      status.textContent = 'Image details saved.';
    } catch (error) { status.textContent = error.message; }
  });
})();
