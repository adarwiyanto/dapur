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

// Patch 20260613 - finished product edit modal only
(() => {
  const modal = document.querySelector('[data-finished-modal]');
  if (!modal) return;

  const fields = {
    id: modal.querySelector('[data-finished-field="id"]'),
    code: modal.querySelector('[data-finished-field="code"]'),
    sku: modal.querySelector('[data-finished-field="sku"]'),
    name: modal.querySelector('[data-finished-field="name"]'),
    category: modal.querySelector('[data-finished-field="category"]'),
    unit: modal.querySelector('[data-finished-field="unit"]'),
    transfer_price: modal.querySelector('[data-finished-field="transfer_price"]'),
    is_active: modal.querySelector('[data-finished-field="is_active"]')
  };

  const openModal = (button) => {
    fields.id.value = button.dataset.id || '';
    fields.code.value = button.dataset.code || '';
    fields.sku.value = button.dataset.sku || '';
    fields.name.value = button.dataset.name || '';
    fields.category.value = button.dataset.category || '';
    fields.unit.value = button.dataset.unit || 'pack';
    fields.transfer_price.value = button.dataset.transferPrice || '0';
    fields.is_active.checked = button.dataset.active === '1';
    modal.hidden = false;
    document.body.classList.add('modal-open');
    window.setTimeout(() => (fields.code || fields.sku || fields.transfer_price).focus(), 0);
  };

  const closeModal = () => {
    modal.hidden = true;
    document.body.classList.remove('modal-open');
  };

  document.addEventListener('click', (event) => {
    const editButton = event.target.closest('[data-finished-edit]');
    if (editButton) {
      event.preventDefault();
      openModal(editButton);
      return;
    }

    if (event.target.closest('[data-finished-modal-close]') || event.target === modal) {
      event.preventDefault();
      closeModal();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !modal.hidden) closeModal();
  });
})();
