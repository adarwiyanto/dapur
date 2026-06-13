document.addEventListener('click', e => {
  const b = e.target.closest('[data-confirm]');
  if (b && !confirm(b.dataset.confirm)) {
    e.preventDefault();
  }
});

document.addEventListener('submit', async e => {
  const form = e.target.closest('form[data-product-import]');
  if (!form) return;

  e.preventDefault();

  const box = document.getElementById('product-import-progress');
  const bar = box ? box.querySelector('[data-import-bar]') : null;
  const pct = box ? box.querySelector('[data-import-percent]') : null;
  const status = box ? box.querySelector('[data-import-status]') : null;
  const button = form.querySelector('button[type="submit"],button:not([type])');
  let progress = 8;
  let timer = null;

  const setProgress = (value, text) => {
    progress = Math.max(0, Math.min(100, value));
    if (box) box.hidden = false;
    if (bar) bar.style.width = progress + '%';
    if (pct) pct.textContent = Math.round(progress) + '%';
    if (status && text) status.textContent = text;
  };

  setProgress(8, 'Menghubungi API toko...');
  if (button) {
    button.disabled = true;
    button.dataset.originalText = button.textContent;
    button.textContent = 'Import berjalan...';
  }

  timer = window.setInterval(() => {
    if (progress < 35) setProgress(progress + 7, 'Membaca daftar produk dari toko...');
    else if (progress < 70) setProgress(progress + 4, 'Menyimpan produk ke dapur...');
    else if (progress < 92) setProgress(progress + 1, 'Finalisasi mapping produk...');
  }, 350);

  try {
    const response = await fetch(form.dataset.importUrl || form.action || window.location.href, {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      headers: {'X-Requested-With': 'XMLHttpRequest'}
    });
    const text = await response.text();
    let data = null;
    try {
      data = JSON.parse(text);
    } catch (err) {
      throw new Error('Response server bukan JSON valid: ' + text.slice(0, 180));
    }
    if (!response.ok || !data.ok) {
      throw new Error(data.message || ('Import gagal. HTTP ' + response.status));
    }
    setProgress(100, data.message || 'Import produk selesai.');
    window.setTimeout(() => window.location.reload(), 900);
  } catch (err) {
    setProgress(100, 'Import gagal: ' + err.message);
    if (bar) bar.classList.add('is-error');
  } finally {
    if (timer) window.clearInterval(timer);
    if (button) {
      button.disabled = false;
      button.textContent = button.dataset.originalText || 'Import Semua Elemen Produk';
    }
  }
});
