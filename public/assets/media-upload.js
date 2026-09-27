window.uploadWebpImage = async (file, csrf, title = '') => {
  if (!file || !file.type.startsWith('image/')) throw new Error('Choose an image file.');
  if (file.size > 25 * 1024 * 1024) throw new Error('Image must be under 25 MB.');
  const bitmap = await createImageBitmap(file);
  try {
    const scale = Math.min(1, 1920 / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(bitmap.width * scale));
    canvas.height = Math.max(1, Math.round(bitmap.height * scale));
    const context = canvas.getContext('2d', {alpha: true});
    if (!context) throw new Error('Image conversion is unavailable in this browser.');
    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/webp', 0.84));
    if (!blob || blob.type !== 'image/webp') throw new Error('WebP conversion failed.');
    const data = new FormData();
    data.append('csrf', csrf);
    data.append('title', title || file.name.replace(/\.[^.]+$/, ''));
    data.append('image', blob, file.name.replace(/\.[^.]+$/, '') + '.webp');
    const response = await fetch('/admin/media/upload', {method:'POST', body:data, credentials:'same-origin'});
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || 'Upload failed.');
    return result;
  } finally { bitmap.close(); }
};
