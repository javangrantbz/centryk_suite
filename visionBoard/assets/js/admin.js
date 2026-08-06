/* Admin UI helpers. */
(function () {
  // Mobile nav toggle
  const toggle = document.getElementById('navToggle');
  const menu = document.getElementById('navMenu');
  if (toggle && menu) {
    toggle.addEventListener('click', () => menu.classList.toggle('hidden'));
  }

  // Lightweight drag sorting for playlist items, QR codes, and marquee rows.
  document.querySelectorAll('[data-sortable-list]').forEach((list) => {
    let dragging = null;

    function syncIndexes() {
      list.querySelectorAll('[data-sort-index]').forEach((el, i) => {
        el.textContent = String(i + 1);
      });
    }

    list.addEventListener('dragstart', (e) => {
      if (!e.target.closest('.drag-handle')) {
        e.preventDefault();
        return;
      }
      const item = e.target.closest('[data-sort-id]');
      if (!item) return;
      dragging = item;
      item.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
    });

    list.addEventListener('dragend', () => {
      if (dragging) dragging.classList.remove('dragging');
      dragging = null;
      syncIndexes();
    });

    list.addEventListener('dragover', (e) => {
      if (!dragging) return;
      e.preventDefault();
      const items = [...list.querySelectorAll('[data-sort-id]:not(.dragging)')];
      const after = items.find((item) => e.clientY <= item.getBoundingClientRect().top + item.offsetHeight / 2);
      if (after) list.insertBefore(dragging, after);
      else list.appendChild(dragging);
    });

    const form = list.parentElement.querySelector('[data-sort-form]') || document.querySelector('[data-sort-form]');
    if (form) {
      form.addEventListener('submit', () => {
        const order = [...list.querySelectorAll('[data-sort-id]')].map((item) => item.dataset.sortId).join(',');
        const input = form.querySelector('input[name="order"]');
        if (input) input.value = order;
      });
    }
  });

  // Drag-and-drop upload affordance for the media library.
  document.querySelectorAll('[data-dropzone]').forEach((zone) => {
    const input = zone.querySelector('input[type="file"]');
    if (!input) return;
    ['dragenter', 'dragover'].forEach((eventName) => {
      zone.addEventListener(eventName, (e) => {
        e.preventDefault();
        zone.classList.add('drop-active');
      });
    });
    ['dragleave', 'drop'].forEach((eventName) => {
      zone.addEventListener(eventName, (e) => {
        e.preventDefault();
        zone.classList.remove('drop-active');
      });
    });
    zone.addEventListener('drop', (e) => {
      if (e.dataTransfer.files.length) {
        input.files = e.dataTransfer.files;
      }
    });
  });

  // QR previews in Settings use the same bundled offline QR generator as the TV.
  document.querySelectorAll('[data-qr-url]').forEach((box) => {
    if (typeof qrcode !== 'function') return;
    const url = box.dataset.qrUrl;
    if (!url) return;
    try {
      const qr = qrcode(0, 'M');
      qr.addData(url);
      qr.make();
      box.innerHTML = qr.createImgTag(2, 2);
    } catch (e) {
      box.textContent = '';
    }
  });
})();
