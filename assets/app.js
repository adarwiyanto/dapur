window.addEventListener('pageshow', () => { document.body.classList.remove('is-loading','modal-open'); });
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
  const sourceType = (form.querySelector('[name="source_type"]') || {}).value || 'store';
  const sourceLabel = sourceType === 'hope' ? 'HOPe/HP' : 'toko';
  let progress = 8;
  let timer = null;

  const setProgress = (value, text) => {
    progress = Math.max(0, Math.min(100, value));
    if (box) box.hidden = false;
    if (bar) bar.style.width = progress + '%';
    if (pct) pct.textContent = Math.round(progress) + '%';
    if (status && text) status.textContent = text;
  };

  if (bar) bar.classList.remove('is-error');
  setProgress(8, 'Menghubungi API ' + sourceLabel + '...');
  if (button) {
    button.disabled = true;
    button.dataset.originalText = button.textContent;
    button.textContent = 'Import berjalan...';
  }

  timer = window.setInterval(() => {
    if (progress < 35) setProgress(progress + 7, 'Membaca daftar produk dari ' + sourceLabel + '...');
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
      button.textContent = button.dataset.originalText || 'Impor Produk';
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


// Patch 20260613 - finished product search/filter + import modal only
(() => {
  const table = document.querySelector('[data-finished-table]');
  const search = document.querySelector('[data-finished-search]');
  const source = document.querySelector('[data-finished-source]');
  const stock = document.querySelector('[data-finished-stock]');
  const active = document.querySelector('[data-finished-active]');
  const reset = document.querySelector('[data-finished-filter-reset]');
  const rows = table ? Array.from(table.querySelectorAll('[data-finished-row]')) : [];
  const empty = table ? table.querySelector('[data-finished-empty]') : null;

  const normalize = (value) => (value || '').toString().toLowerCase().trim();

  const applyFilter = () => {
    if (!table) return;
    const q = normalize(search ? search.value : '');
    const sourceValue = source ? source.value : '';
    const stockValue = stock ? stock.value : '';
    const activeValue = active ? active.value : '';
    let visible = 0;

    rows.forEach((row) => {
      const matchSearch = !q || normalize(row.dataset.search).includes(q);
      const matchSource = !sourceValue || row.dataset.source === sourceValue;
      const matchStock = !stockValue || row.dataset.stock === stockValue;
      const matchActive = activeValue === '' || row.dataset.active === activeValue;
      const show = matchSearch && matchSource && matchStock && matchActive;
      row.hidden = !show;
      if (show) visible += 1;
    });

    if (empty) empty.hidden = visible !== 0;
  };

  [search, source, stock, active].forEach((el) => {
    if (!el) return;
    el.addEventListener('input', applyFilter);
    el.addEventListener('change', applyFilter);
  });

  if (reset) {
    reset.addEventListener('click', () => {
      if (search) search.value = '';
      if (source) source.value = '';
      if (stock) stock.value = '';
      if (active) active.value = '';
      applyFilter();
      if (search) search.focus();
    });
  }

  applyFilter();
})();

(() => {
  const modal = document.querySelector('[data-finished-import-modal]');
  if (!modal) return;

  const firstField = modal.querySelector('select, input, button');
  const sourceType = modal.querySelector('[data-import-source-type]');
  const storeField = modal.querySelector('[data-import-store-field]');
  const hopeField = modal.querySelector('[data-import-hope-field]');
  const storeSelect = modal.querySelector('[name="store_id"]');
  const hopeSelect = modal.querySelector('[name="connection_id"]');
  if (sourceType && sourceType.value === 'store' && storeSelect && !storeSelect.value && hopeSelect && hopeSelect.value) {
    sourceType.value = 'hope';
  }
  const syncSourceFields = () => {
    const isHope = sourceType && sourceType.value === 'hope';
    if (storeField) storeField.hidden = !!isHope;
    if (hopeField) hopeField.hidden = !isHope;
  };
  if (sourceType) {
    sourceType.addEventListener('change', syncSourceFields);
    syncSourceFields();
  }
  const openModal = () => {
    modal.hidden = false;
    document.body.classList.add('modal-open');
    window.setTimeout(() => firstField && firstField.focus(), 0);
  };
  const closeModal = () => {
    modal.hidden = true;
    document.body.classList.remove('modal-open');
  };

  document.addEventListener('click', (event) => {
    const openButton = event.target.closest('[data-finished-import-open]');
    if (openButton) {
      event.preventDefault();
      if (!openButton.disabled) openModal();
      return;
    }
    if (event.target.closest('[data-finished-import-close]') || event.target === modal) {
      event.preventDefault();
      closeModal();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !modal.hidden) closeModal();
  });
})();

// Patch 20260623 - general submit feedback / double submit guard + overlay anti-lag
(() => {
  const ensureOverlay = () => {
    let overlay = document.querySelector('[data-global-loading]');
    if (overlay) return overlay;
    overlay = document.createElement('div');
    overlay.className = 'global-loading';
    overlay.setAttribute('data-global-loading', '');
    overlay.hidden = true;
    overlay.innerHTML = '<div class="global-loading-card"><div class="global-loading-spinner" aria-hidden="true"></div><div><strong>Memproses...</strong><span>Perintah sudah diterima. Tunggu sebentar, jangan klik dua kali.</span></div></div>';
    document.body.appendChild(overlay);
    return overlay;
  };
  const showOverlay = () => {
    const overlay = ensureOverlay();
    overlay.hidden = false;
    document.body.classList.add('is-busy');
  };

  document.addEventListener('submit', (event) => {
    const form = event.target.closest('form');
    if (!form || form.matches('[data-product-import]') || form.matches('[data-store-api-test]') || form.dataset.noSubmitLock === '1') return;
    if (form.dataset.submitting === '1') {
      event.preventDefault();
      return;
    }
    form.dataset.submitting = '1';
    const buttons = Array.from(form.querySelectorAll('button[type="submit"], button:not([type])'));
    buttons.forEach((button, index) => {
      if (!button.dataset.originalText) button.dataset.originalText = button.textContent || '';
      if (index === 0) button.textContent = button.dataset.loadingText || 'Memproses...';
      button.disabled = true;
    });
    showOverlay();
  }, true);

  window.addEventListener('pageshow', () => {
    const overlay = document.querySelector('[data-global-loading]');
    if (overlay) overlay.hidden = true;
    document.body.classList.remove('is-busy');
    document.querySelectorAll('form[data-submitting="1"]').forEach((form) => {
      form.dataset.submitting = '0';
      form.querySelectorAll('button').forEach((button) => {
        button.disabled = false;
        if (button.dataset.originalText) button.textContent = button.dataset.originalText;
      });
    });
  });
})();

// Patch 20260623f - Toko & API test inline with safe JSON fallback
(() => {
  const statusBox = document.getElementById('store-api-status');
  const showStatus = (ok, message) => {
    if (!statusBox) return;
    statusBox.hidden = false;
    statusBox.className = 'store-api-status notice ' + (ok ? 'ok' : 'err');
    statusBox.textContent = message || (ok ? 'Sukses.' : 'Gagal.');
  };

  document.addEventListener('submit', async (event) => {
    const form = event.target.closest('form[data-store-api-test]');
    if (!form) return;
    event.preventDefault();
    if (form.dataset.submitting === '1') return;
    form.dataset.submitting = '1';

    const submitter = event.submitter && event.submitter.matches('button') ? event.submitter : form.querySelector('button');
    if (submitter) {
      submitter.dataset.originalText = submitter.dataset.originalText || submitter.textContent || '';
      submitter.textContent = submitter.dataset.loadingText || 'Memproses...';
      submitter.disabled = true;
      submitter.classList.add('is-loading');
    }
    showStatus(true, 'Memproses test API...');

    try {
      const response = await fetch(form.action || window.location.href, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}
      });
      const text = await response.text();
      let data = null;
      try { data = JSON.parse(text); } catch (err) { data = null; }
      if (!data) {
        showStatus(false, 'Server membalas bukan JSON. Halaman akan dimuat ulang agar status asli tampil.');
        window.setTimeout(() => { form.removeAttribute('data-store-api-test'); form.submit(); }, 600);
        return;
      }
      showStatus(!!data.ok, data.message || (data.ok ? 'Sukses.' : 'Gagal.'));
    } catch (err) {
      showStatus(false, 'Aksi API gagal tanpa reload: ' + err.message);
    } finally {
      form.dataset.submitting = '0';
      if (submitter) {
        submitter.disabled = false;
        submitter.classList.remove('is-loading');
        submitter.textContent = submitter.dataset.originalText || 'Test';
      }
    }
  });
})();

// Patch 20260711b - modal detail pegawai dan baris input kegiatan dinamis
(() => {
  const modal = document.querySelector('[data-employee-modal]');
  if (modal) {
    const closeUrl = new URL(window.location.href);
    closeUrl.searchParams.delete('detail_employee');
    closeUrl.searchParams.delete('from');
    closeUrl.searchParams.delete('to');
    const closeModal = () => { window.location.href = closeUrl.toString(); };
    modal.addEventListener('click', (event) => {
      if (event.target === modal) closeModal();
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') closeModal();
    });
  }

  const form = document.querySelector('[data-bulk-activity-form]');
  if (!form) return;
  const rows = form.querySelector('[data-activity-rows]');
  const template = form.querySelector('[data-activity-row-template]');
  const addButton = form.querySelector('[data-add-activity-row]');
  if (!rows || !template || !addButton) return;

  addButton.addEventListener('click', () => {
    rows.appendChild(template.content.cloneNode(true));
    const added = rows.lastElementChild;
    if (added) added.querySelector('select')?.focus();
  });

  rows.addEventListener('click', (event) => {
    const button = event.target.closest('[data-remove-activity-row]');
    if (!button) return;
    const row = button.closest('[data-activity-row]');
    if (row) row.remove();
  });
})();

// Patch 20260711c - riwayat modal, detail/cetak dokumen, stok responsif, dan baris transfer dinamis
(() => {
  const visibleModalSelector = '.history-modal:not([hidden]),.record-modal:not([hidden])';
  const syncBodyLock = () => document.body.classList.toggle('modal-open', !!document.querySelector(visibleModalSelector));
  const openModal = (modal) => {
    if (!modal) return;
    modal.hidden = false;
    syncBodyLock();
    window.setTimeout(() => modal.querySelector('button,input,select')?.focus(), 0);
  };
  const closeModal = (modal) => {
    if (!modal) return;
    modal.hidden = true;
    syncBodyLock();
  };
  const findByData = (attribute, value) => {
    const safe = window.CSS && CSS.escape ? CSS.escape(value) : value.replace(/["\\]/g, '\\$&');
    return document.querySelector('[' + attribute + '="' + safe + '"]');
  };

  document.addEventListener('click', (event) => {
    const historyOpen = event.target.closest('[data-history-open]');
    if (historyOpen) {
      event.preventDefault();
      openModal(findByData('data-history-modal', historyOpen.dataset.historyOpen || ''));
      return;
    }
    const recordOpen = event.target.closest('[data-record-open]');
    if (recordOpen) {
      event.preventDefault();
      openModal(findByData('data-record-modal', recordOpen.dataset.recordOpen || ''));
      return;
    }
    const historyClose = event.target.closest('[data-history-close]');
    if (historyClose) {
      event.preventDefault();
      closeModal(historyClose.closest('[data-history-modal]'));
      return;
    }
    const recordClose = event.target.closest('[data-record-close]');
    if (recordClose) {
      event.preventDefault();
      closeModal(recordClose.closest('[data-record-modal]'));
      return;
    }
    if (event.target.matches('.record-modal')) {
      closeModal(event.target);
      return;
    }
    if (event.target.matches('.history-modal')) {
      closeModal(event.target);
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    const record = document.querySelector('.record-modal:not([hidden])');
    if (record) {
      closeModal(record);
      return;
    }
    const history = document.querySelector('.history-modal:not([hidden])');
    if (history) closeModal(history);
  });

  document.querySelectorAll('[data-history-filter]').forEach((form) => {
    const range = form.querySelector('[data-history-range]');
    const customFields = form.querySelectorAll('[data-history-custom]');
    const syncCustom = () => customFields.forEach((field) => { field.hidden = !range || range.value !== 'custom'; });
    range?.addEventListener('change', syncCustom);
    syncCustom();
  });

  document.querySelectorAll('[data-history-modal][data-auto-open="1"]').forEach(openModal);
  document.querySelectorAll('[data-record-modal][data-auto-open="1"]').forEach(openModal);
  window.addEventListener('pageshow', syncBodyLock);

  document.addEventListener('click', (event) => {
    const printButton = event.target.closest('[data-print-target]');
    if (!printButton) return;
    event.preventDefault();
    const id = printButton.dataset.printTarget || '';
    const report = findByData('data-print-report', id);
    if (!report) return;
    document.querySelectorAll('.record-report.is-printing').forEach((el) => el.classList.remove('is-printing'));
    report.classList.add('is-printing');
    document.body.classList.add('print-record-report');
    const cleanup = () => {
      document.body.classList.remove('print-record-report');
      report.classList.remove('is-printing');
      window.removeEventListener('afterprint', cleanup);
    };
    window.addEventListener('afterprint', cleanup);
    window.print();
    window.setTimeout(cleanup, 1500);
  });

  const transferForm = document.querySelector('[data-transfer-form]');
  if (transferForm) {
    const rows = transferForm.querySelector('[data-transfer-rows]');
    const template = transferForm.querySelector('[data-transfer-row-template]');
    const addButton = transferForm.querySelector('[data-transfer-add]');
    const syncRow = (row) => {
      const select = row?.querySelector('[data-transfer-item]');
      const price = row?.querySelector('[data-transfer-price]');
      const stockCell = row?.querySelector('[data-transfer-stock]');
      const option = select?.selectedOptions?.[0];
      if (!select || !option) return;
      if (!select.value) {
        if (stockCell) stockCell.textContent = 'Pilih item';
        return;
      }
      const stock = option.dataset.stock || '0';
      const unit = option.dataset.unit || '';
      if (stockCell) stockCell.textContent = stock + (unit ? ' ' + unit : '');
      if (price && price.value === '') price.value = option.dataset.price || '0';
    };
    addButton?.addEventListener('click', () => {
      if (!rows || !template) return;
      rows.appendChild(template.content.cloneNode(true));
      rows.lastElementChild?.querySelector('select')?.focus();
    });
    rows?.addEventListener('click', (event) => {
      const remove = event.target.closest('[data-transfer-remove]');
      if (!remove) return;
      const row = remove.closest('[data-transfer-row]');
      row?.remove();
      if (rows.children.length === 0 && template) rows.appendChild(template.content.cloneNode(true));
    });
    rows?.addEventListener('change', (event) => {
      if (!event.target.matches('[data-transfer-item]')) return;
      const row = event.target.closest('[data-transfer-row]');
      const price = row?.querySelector('[data-transfer-price]');
      if (price) price.value = '';
      syncRow(row);
    });
    rows?.querySelectorAll('[data-transfer-row]').forEach(syncRow);
  }

  const stockSearch = document.querySelector('[data-stock-search]');
  if (stockSearch) {
    const rows = Array.from(document.querySelectorAll('[data-stock-row]'));
    const filter = () => {
      const query = (stockSearch.value || '').toLowerCase().trim();
      rows.forEach((row) => { row.hidden = !!query && !(row.dataset.search || '').includes(query); });
    };
    stockSearch.addEventListener('input', filter);
  }
})();
