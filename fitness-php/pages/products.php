<?php
$pageTitle = 'Товари';
$pageCss   = 'products';
require __DIR__ . '/../partials/head.php';
?>
<body>
<div class="app-layout">

  <!-- aside і header НЕ пишемо — sidebar.js вставить їх сам -->

    <main class="app-main">

    <div class="page-header prod-page-header">
      <div class="prod-header-top">
        <div class="prod-toolbar">
          <div class="search-wrap">
            <span class="search-icon">&#128269;</span>
            <input type="text" id="search-input"
                   placeholder="Назва, штрих-код, постачальник..."
                   oninput="scheduleSearch()">
          </div>
          <select id="cat-filter" onchange="loadProducts()">
            <option value="">Всі категорії</option>
          </select>
        </div>
        <div class="prod-header-actions">
          <button class="btn btn-ghost"   id="btn-arrival" data-shift-action onclick="openArrivalModal()" style="display:none">+ Прихід</button>
          <button class="btn btn-primary" id="btn-sell"    data-shift-action onclick="openSellModal()"    style="display:none">&#128722; Продати</button>
          <button class="btn btn-ghost"   id="btn-add"     data-shift-action onclick="openProductModal(null)" style="display:none">+ Товар</button>
          <button class="btn btn-ghost btn-low-stock" id="btn-low-stock"
                  onclick="toggleLowStock(this)" style="display:none">&#9888; Мало</button>

        </div>
      </div>
    </div>

    <!-- Вкладки — повна ширина, 2 ряди на мобільному -->
    <div class="prod-tabs">
      <button class="prod-tab-btn active" onclick="switchTab('catalog',this)">&#128230; Каталог</button>
      <button class="prod-tab-btn"        onclick="switchTab('sales',this)">&#128722; Продажі</button>
      <button class="prod-tab-btn"        onclick="switchTab('arrivals',this)">&#128666; Приходи</button>
      <button class="prod-tab-btn"        onclick="switchTab('archive',this)">&#128452; Архів</button>
    </div>

    <!-- ═══ КАТАЛОГ ═══ -->
    <div class="prod-tab-content active" id="tab-catalog">

      <div id="products-grid" class="products-grid">
        <div style="grid-column:1/-1">
          <div class="loader"><div class="spinner"></div> Завантаження...</div>
        </div>
      </div>
    </div>

    <!-- ═══ ПРОДАЖІ ═══ -->
    <div class="prod-tab-content" id="tab-sales">
      <div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;align-items:flex-end">
        <div class="form-group" style="margin:0">
          <label>Від</label>
          <input type="date" id="sales-from" onchange="loadSales()">
        </div>
        <div class="form-group" style="margin:0">
          <label>До</label>
          <input type="date" id="sales-to" onchange="loadSales()">
        </div>
      </div>
      <div id="sales-summary" style="display:none;margin-bottom:16px"></div>
      <div class="card" style="padding:0;overflow:hidden">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Товар</th>
                <th>К-сть</th>
                <th>Сума</th>
                <th>Клієнт</th>
                <th>Спосіб</th>
                <th>Менеджер</th>
                <th>Дата</th>
              </tr>
            </thead>
            <tbody id="sales-tbody">
              <tr><td colspan="7"><div class="loader"><div class="spinner"></div></div></td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ═══ ПРИХОДИ ═══ -->
    <div class="prod-tab-content" id="tab-arrivals">
      <div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;align-items:flex-end">
        <div class="form-group" style="margin:0">
          <label>Від</label>
          <input type="date" id="arr-from" onchange="loadArrivals()">
        </div>
        <div class="form-group" style="margin:0">
          <label>До</label>
          <input type="date" id="arr-to" onchange="loadArrivals()">
        </div>
      </div>
      <div class="card" style="padding:0;overflow:hidden">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Товар</th>
                <th>Операція</th>
                <th>Статус</th>
                <th>К-сть</th>
                <th>Ціна закупки</th>
                <th>Сума</th>
                <th>Постачальник</th>
                <th>Менеджер</th>
                <th>Дата</th>
              </tr>
            </thead>
            <tbody id="arrivals-tbody">
              <tr><td colspan="9"><div class="loader"><div class="spinner"></div></div></td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ═══ АРХІВ ═══ -->
    <div class="prod-tab-content" id="tab-archive">
      <div id="archive-grid" class="products-grid">
        <div style="grid-column:1/-1"><div class="loader"><div class="spinner"></div></div></div>
      </div>
    </div>

  </main>
</div>

<!-- ════ МОДАЛКА: ДЕТАЛІ / РЕДАГУВАННЯ ТОВАРУ ════ -->
<div class="modal-overlay" id="modal-product">
  <div class="modal" style="max-width:520px">
    <button class="modal-close" onclick="closeModal('modal-product')">&#10005;</button>
    <h2 class="modal-title" id="modal-product-title">Новий товар</h2>
    <div id="prod-error" class="alert alert-error" style="display:none"></div>

    <!-- Переглянути -->
    <div id="prod-view-section" style="display:none">
      <div style="display:flex;gap:16px;margin-bottom:16px;align-items:flex-start">
        <div class="product-img" style="width:80px;height:80px;border-radius:var(--radius-md);flex-shrink:0" id="pv-img">&#128230;</div>
        <div style="flex:1">
          <div style="font-size:18px;font-weight:600" id="pv-name">—</div>
          <div style="font-size:13px;color:var(--text-muted);margin-top:2px" id="pv-cat">—</div>
          <div style="font-size:22px;font-weight:700;color:var(--accent);margin-top:6px" id="pv-price">—</div>
        </div>
      </div>
      <div id="pv-rows"></div>
      <div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap" id="pv-actions"></div>
    </div>

    <!-- Форма -->
    <div id="prod-form-section">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px">
        <div class="form-group" style="grid-column:1/-1">
          <label>Назва *</label>
          <input type="text" id="pf-name" placeholder="Протеїн Power Pro 1кг" maxlength="180">
        </div>
        <div class="form-group">
          <label>Категорія</label>
          <input type="text" id="pf-category" list="cat-list" placeholder="Протеїн, Вода...">
          <datalist id="cat-list"></datalist>
        </div>
        <div class="form-group">
          <label>Постачальник</label>
          <input type="text" id="pf-supplier" placeholder="Power Pro">
        </div>
        <div class="form-group">
          <label>Ціна закупки (грн)</label>
          <input type="number" id="pf-purchase" placeholder="0" min="0" step="0.01"
                 oninput="calcMargin()">
        </div>
        <div class="form-group">
          <label>Ціна продажу (грн)</label>
          <input type="number" id="pf-sale" placeholder="0" min="0" step="0.01"
                 oninput="calcMargin()">
        </div>
        <div class="form-group">
          <label>Штрих-код</label>
          <input type="text" id="pf-barcode" placeholder="4820...">
        </div>
        <div class="form-group">
          <label>Мін. залишок (сповіщення)</label>
          <input type="number" id="pf-stock-min" placeholder="5" min="0">
        </div>
        <div class="form-group" style="grid-column:1/-1">
          <label>Фото (URL)</label>
          <div style="display:flex;gap:8px;align-items:center">
            <div style="position:relative;flex:1">
              <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);
                           color:var(--text-muted);font-size:14px;pointer-events:none">🔗</span>
              <input type="url" id="pf-photo" placeholder="https://example.com/image.jpg"
                     style="padding-left:32px" oninput="previewPhoto()">
            </div>
            <div id="pf-photo-preview" style="
              width:48px;height:48px;min-width:48px;border-radius:var(--radius-sm);
              background:var(--bg-elevated);border:1px solid var(--border-light);
              display:flex;align-items:center;justify-content:center;
              font-size:20px;overflow:hidden;color:var(--text-muted)">📷</div>
          </div>
        </div>
      </div>
      <div id="margin-hint" style="font-size:12px;color:var(--text-muted);margin:-8px 0 12px"></div>
      <div style="display:flex;gap:10px">
        <button class="btn btn-primary" id="btn-save-prod" onclick="saveProduct()">Зберегти</button>
        <button class="btn btn-ghost"   onclick="closeModal('modal-product')">Скасувати</button>
      </div>
    </div>
  </div>
</div>

<!-- ════ МОДАЛКА: ПРИХІД ════ -->
<div class="modal-overlay" id="modal-arrival">
  <div class="modal" style="max-width:480px">
    <button class="modal-close" onclick="closeModal('modal-arrival')">&#10005;</button>
    <h2 class="modal-title">Операція з товаром</h2>
    <div id="arr-error" class="alert alert-error" style="display:none"></div>

    <div class="form-group">
      <label>Товар *</label>
      <select id="arr-product" onchange="onArrProductChange()">
        <option value="">— Оберіть товар —</option>
      </select>
    </div>

    <!-- Операція -->
    <div class="form-group">
      <label>Операція *</label>
      <div style="display:flex;gap:8px;flex-wrap:wrap" id="arr-operation-btns">
        <button type="button" class="arr-op-btn active" data-op="arrival"  onclick="selectArrOp(this)">&#10145;&#65039; Прихід</button>
        <button type="button" class="arr-op-btn"        data-op="overdue"  onclick="selectArrOp(this)">&#128993; Прострочка</button>
        <button type="button" class="arr-op-btn"        data-op="repack"   onclick="selectArrOp(this)">&#129516; Розфасування</button>
        <button type="button" class="arr-op-btn"        data-op="transfer" onclick="selectArrOp(this)">&#128683; Перенесення склад</button>
      </div>
      <input type="hidden" id="arr-operation" value="arrival">
    </div>

    <!-- Статус (лише для операції arrival) -->
    <div class="form-group" id="arr-status-wrap">
      <label>Статус *</label>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button type="button" class="arr-status-btn active" data-status="paid"    onclick="selectArrStatus(this)">&#9989; Оплачено</button>
        <button type="button" class="arr-status-btn"        data-status="unpaid"  onclick="selectArrStatus(this)">&#128308; Не оплачено</button>
        <button type="button" class="arr-status-btn"        data-status="pending" onclick="selectArrStatus(this)">&#9201; Замовлено</button>
      </div>
      <input type="hidden" id="arr-status" value="paid">
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px">
      <!-- Кількість: для pending — очікувана -->
      <div class="form-group">
        <label id="arr-qty-label">Кількість *</label>
        <input type="number" id="arr-qty" value="1" min="1" step="1" oninput="calcArrTotal()">
      </div>
      <div class="form-group">
        <label>Постачальник</label>
        <input type="text" id="arr-supplier" placeholder="">
      </div>
      <!-- Ціни — приховуємо для pending і мінус-операцій -->
      <div class="form-group" id="arr-purchase-wrap">
        <label>Ціна закупки (грн)</label>
        <input type="number" id="arr-purchase" placeholder="0" min="0" step="0.01" oninput="calcArrTotal()">
      </div>
      <div class="form-group" id="arr-sale-wrap">
        <label>Ціна продажу (грн)</label>
        <input type="number" id="arr-sale" placeholder="0" min="0" step="0.01">
      </div>
    </div>

    <div class="form-group">
      <label>Примітка</label>
      <input type="text" id="arr-notes" placeholder="Необов'язково">
    </div>
    <div id="arr-total" style="font-size:13px;color:var(--text-secondary);margin-bottom:16px"></div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-primary" id="btn-arr-submit" onclick="submitArrival()">Підтвердити</button>
      <button class="btn btn-ghost"   onclick="closeModal('modal-arrival')">Скасувати</button>
    </div>
  </div>
</div>

<!-- ════ МОДАЛКА: ПРОДАЖ ════ -->
<div class="modal-overlay" id="modal-sell">
  <div class="modal" style="max-width:460px">
    <button class="modal-close" onclick="closeModal('modal-sell')">&#10005;</button>
    <h2 class="modal-title">Продаж товару</h2>
    <div id="sell-error" class="alert alert-error" style="display:none"></div>
    <div class="form-group">
      <label>Товар *</label>
      <select id="sell-product" onchange="onSellProductChange()">
        <option value="">— Оберіть товар —</option>
      </select>
    </div>
    <div id="sell-stock-info" style="font-size:12px;color:var(--text-muted);margin:-8px 0 12px"></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px">
      <div class="form-group">
        <label>Кількість</label>
        <div class="qty-control">
          <button class="qty-btn" onclick="adjQty(-1)">−</button>
          <input type="number" id="sell-qty" class="qty-input" value="1" min="1"
                 oninput="recalcSell()">
          <button class="qty-btn" onclick="adjQty(1)">+</button>
        </div>
      </div>
      <div class="form-group">
        <label>Ціна продажу</label>
        <input type="number" id="sell-price" min="0" step="0.01" oninput="recalcSell()">
      </div>
      <div class="form-group">
        <label>Знижка (грн)</label>
        <input type="number" id="sell-discount" value="0" min="0" step="1" oninput="recalcSell()">
      </div>
      <div class="form-group">
        <label>Спосіб оплати</label>
        <select id="sell-method" onchange="onSellMethodChange()">
          <option value="cash">Готівка</option>
          <option value="card">Карта</option>
          <option value="terminal">Термінал</option>
          <option value="deposit">З депозиту</option>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label>Клієнт <span id="sell-client-required" style="color:var(--danger);display:none">*</span>
        <span style="font-size:11px;color:var(--text-muted)" id="sell-client-note">(обов'язково при оплаті депозитом)</span>
      </label>
      <div style="position:relative">
        <input type="text" id="sell-client-input"
               placeholder="Введіть ім'я або телефон..."
               oninput="searchSellClient()" autocomplete="off">
        <div id="sell-client-dd" style="
          display:none;position:absolute;top:100%;left:0;right:0;
          background:var(--bg-elevated);border:1px solid var(--border-light);
          border-radius:var(--radius-sm);z-index:100;max-height:180px;
          overflow-y:auto;box-shadow:var(--shadow-md);margin-top:4px;
        "></div>
      </div>
      <div id="sell-client-badge" style="display:none;margin-top:6px;font-size:13px;
           padding:6px 10px;background:var(--accent-dim);border-radius:var(--radius-sm)">
        <span id="sell-client-name">—</span>
        <button onclick="clearSellClient()" style="float:right;background:none;border:none;
                cursor:pointer;color:var(--text-muted)">&#10005;</button>
      </div>
    </div>
    <div class="sell-summary" id="sell-summary" style="display:none">
      <div class="sell-summary-row"><span>Ціна × к-сть</span><span id="ss-subtotal">—</span></div>
      <div class="sell-summary-row" id="ss-disc-row" style="display:none">
        <span>Знижка</span><span id="ss-disc" style="color:var(--success)">—</span>
      </div>
      <div class="sell-summary-row total"><span>Разом</span><span id="ss-total">—</span></div>
      <div class="sell-summary-row" style="color:var(--text-secondary)">
        <span>Прибуток</span><span id="ss-profit" style="color:var(--success)">—</span>
      </div>
    </div>
    <div style="display:flex;gap:10px">
      <button class="btn btn-primary" id="btn-sell-submit" onclick="submitSell()">Продати</button>
      <button class="btn btn-ghost"   onclick="closeModal('modal-sell')">Скасувати</button>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../partials/scripts.php'; ?>
<script>
let state = {
  products: [], canWrite: false, isOwner: false, lowStock: false,
  displayFields: null,   // завантажуються з БД
  editProductId: null, sellProductId: null,
  sellClientId: null, clientTimer: null, searchTimer: null,
};

// ── Ініціалізація ─────────────────────────────────────────
window.addEventListener('DOMContentLoaded', async () => {
  const ctx = await initPage({ title: 'Товари' });
  if (!ctx) return;
  guardBlockedClub(ctx.isBlocked);
  window._shiftCtx = ctx;
  applyShiftLock(ctx);
  applyPlanLock(ctx);
  const { u, isSuperAdmin, inClubMode, club, clubRole } = ctx;
  const perms = getPermissions(ctx);
  state.canWrite = perms.canWrite;
  state.isOwner  = perms.isOwner;
  if (state.canWrite) {
    document.getElementById('btn-arrival').style.display   = 'inline-flex';
    document.getElementById('btn-sell').style.display      = 'inline-flex';
    document.getElementById('btn-add').style.display       = 'inline-flex';
    document.getElementById('btn-low-stock').style.display = 'inline-flex';
  }


  // Дати за замовчуванням
  const today  = new Date().toISOString().split('T')[0];
  const month1 = today.substring(0, 8) + '01';
  ['sales-from','arr-from'].forEach(id => document.getElementById(id).value = month1);
  ['sales-to','arr-to'].forEach(id => document.getElementById(id).value = today);

  loadCategories();
  await loadDisplayFields('products');
  loadProducts();
});

// ── Категорії ─────────────────────────────────────────────
async function loadCategories() {
  const res = await api('get_categories', {}, 'products');
  if (!res.success) return;
  const cats = res.categories || [];
  const sel  = document.getElementById('cat-filter');
  const dl   = document.getElementById('cat-list');
  cats.forEach(c => {
    const o = document.createElement('option');
    o.value = c; o.textContent = c;
    sel.appendChild(o.cloneNode(true));
    dl.appendChild(o);
  });
}

// ── Каталог товарів ───────────────────────────────────────
const FIELD_LABELS_PRODUCTS = {
  photo:'Фото', name:'Назва', category:'Категорія',
  supplier:'Постачальник', barcode:'Штрих-код',
  purchase_price:'Ціна закупки', sale_price:'Ціна продажу',
  stock_qty:'Залишок', stock_min:'Мін. залишок', created_at:'Дата',
};
const DEFAULT_FIELDS_PRODUCTS = [
  {key:'photo',visible:true},{key:'name',visible:true},
  {key:'category',visible:true},{key:'purchase_price',visible:true},
  {key:'supplier',visible:true},{key:'sale_price',visible:true},
  {key:'stock_qty',visible:true},
];

async function loadDisplayFields(entity) {
  try {
    const res = await api('get_display_settings', { entity }, 'settings');
    if (res.success && res.fields?.length) {
      state.displayFields = res.fields.map(f => ({
        key: f.field_key, visible: !!parseInt(f.is_visible),
      }));
      return;
    }
  } catch(e) {}
  state.displayFields = DEFAULT_FIELDS_PRODUCTS;
}

let dsModuleLoaded = false;
async function lazyOpenSettings(entity) {
  if (!dsModuleLoaded) {
    const html = await fetch('display_settings_modal.php').then(r => r.text());
    document.body.insertAdjacentHTML('beforeend', html);
    dsModuleLoaded = true;
  }
  openDisplaySettings(entity, (fields) => {
    state.displayFields = fields;
    renderProducts();
  });
}

async function loadProducts() {
  const grid = document.getElementById('products-grid');
  grid.innerHTML = '<div class="loader"><div class="spinner"></div></div>';

  const res = await api('get_list', {
    search:    document.getElementById('search-input').value.trim(),
    category:  document.getElementById('cat-filter').value,
    low_stock: state.lowStock || false,
  }, 'products');

  if (!res.success) {
    grid.innerHTML = `<div class="alert alert-error">${res.error}</div>`;
    return;
  }

  state.products = res.products || [];
  fillProductSelects(state.products);
  renderProducts();
}

function renderProducts() {
  const grid = document.getElementById('products-grid');
  if (!state.products.length) {
    grid.className = 'products-list';
    grid.innerHTML = `
      <div class="empty-state" style="padding:40px;text-align:center">
        <div style="font-size:40px;margin-bottom:12px">&#128230;</div>
        <h3>Товарів ще немає</h3>
        ${state.canWrite ? '<p>Натисніть "+ Товар" щоб додати перший</p>' : ''}
      </div>`;
    return;
  }
  const fields   = (state.displayFields || DEFAULT_FIELDS_PRODUCTS).filter(f => f.visible);
  const isMobile = window.innerWidth <= 640;
  if (isMobile) {
    grid.className = 'products-list';
    grid.innerHTML = state.products.map(p => renderProductCard(p, fields)).join('');
  } else {
    grid.className = 'products-table';
    grid.innerHTML = renderProductTable(state.products, fields);
  }
  applyShiftLock(window._shiftCtx);
  applyPlanLock(window._shiftCtx);
}

function renderProductCell(p, key) {
  switch(key) {
    case 'photo':          return p.photo_url ? `<img src="${esc(p.photo_url)}" onerror="this.src=''">` : '&#128230;';
    case 'name':           return `<strong>${esc(p.name)}</strong>`;
    case 'category':       return `<span class="product-category">${esc(p.category||'—')}</span>`;
    case 'supplier':       return esc(p.supplier||'—');
    case 'barcode':        return esc(p.barcode||'—');
    case 'purchase_price': return `${p.purchase_price} грн`;
    case 'sale_price':     return `<span style="font-weight:700;color:var(--accent)">${p.sale_price} грн</span>`;
    case 'stock_qty':      return `<span class="stock-badge ${p.stock_status}">${p.stock_qty} шт.</span>`;
    case 'stock_min':      return p.stock_min + ' шт.';
    case 'created_at':     return (p.created_at||'').substring(0,10);
    default:               return '';
  }
}

function renderProductTable(products, fields) {
  const hasPh = fields.some(f => f.key === 'photo');
  const cols  = fields.filter(f => f.key !== 'photo');
  return `<table>
    <thead><tr>
      ${hasPh ? '<th style="width:52px"></th>' : ''}
      ${cols.map(f => `<th>${FIELD_LABELS_PRODUCTS[f.key]||f.key}</th>`).join('')}
    </tr></thead>
    <tbody>
      ${products.map(p => `
        <tr class="${p.is_active?'':'inactive'}" onclick="openProductView(${p.id})" style="cursor:pointer">
          ${hasPh ? `<td><div class="product-img-sm">${renderProductCell(p,'photo')}</div></td>` : ''}
          ${cols.map(f => `<td>${renderProductCell(p,f.key)}</td>`).join('')}
        </tr>`).join('')}
    </tbody></table>`;
}

function renderProductCard(p, fields) {
  const photo = p.photo_url
    ? `<img src="${esc(p.photo_url)}" onerror="this.src=''">`
    : '&#128230;';
  return `
    <div class="product-card ${p.is_active?'':'inactive'}" onclick="openProductView(${p.id})">
      <div class="product-img">${photo}</div>
      <div class="product-body">
        <div class="product-card-name">${esc(p.name)}</div>
        ${p.category ? `<div class="product-card-cat">${esc(p.category)}</div>` : ''}
        ${p.supplier ? `<div class="product-card-sup">${esc(p.supplier)}</div>` : ''}
      </div>
      <div class="product-right">
        <div class="product-card-price">${p.sale_price} грн</div>
        <span class="stock-badge ${p.stock_status}">${p.stock_qty} шт.</span>
      </div>
    </div>`;
}

function fillProductSelects(products) {
  const active = products.filter(p => p.is_active && p.stock_qty > 0);
  ['arr-product', 'sell-product'].forEach(selId => {
    const sel = document.getElementById(selId);
    const cur = sel.value;
    while (sel.options.length > 1) sel.remove(1);
    (selId === 'arr-product' ? products.filter(p=>p.is_active) : active).forEach(p => {
      const o = document.createElement('option');
      o.value = p.id;
      o.textContent = `${p.name} (${p.stock_qty} шт.)`;
      o.dataset.purchase = p.purchase_price;
      o.dataset.sale     = p.sale_price;
      o.dataset.supplier = p.supplier || '';
      o.dataset.stock    = p.stock_qty;
      sel.appendChild(o);
    });
    if (cur) sel.value = cur;
  });
}

function toggleLowStock(btn) {
  state.lowStock = !state.lowStock;
  btn.classList.toggle('active', state.lowStock);
  loadProducts();
}

function scheduleSearch() {
  clearTimeout(state.searchTimer);
  state.searchTimer = setTimeout(() => loadProducts(), 350);
}

// ── Перегляд / Редагування товару ────────────────────────
async function openProductView(id) {
  const res = await api('get_one', { id }, 'products');
  if (!res.success) { toast(res.error, 'error'); return; }
  const p = res.product;

  document.getElementById('modal-product-title').textContent = p.name;
  document.getElementById('prod-view-section').style.display = 'block';
  document.getElementById('prod-form-section').style.display = 'none';
  document.getElementById('prod-error').style.display = 'none';

  // Фото
  const imgEl = document.getElementById('pv-img');
  imgEl.innerHTML = p.photo_url
    ? `<img src="${esc(p.photo_url)}" onerror="this.parentNode.innerHTML='&#128230;'">`
    : '&#128230;';

  document.getElementById('pv-name').textContent  = p.name;
  document.getElementById('pv-cat').textContent   = p.category || '—';
  document.getElementById('pv-price').textContent = p.sale_price + ' грн';

  const fields = [
    ['Ціна закупки',   formatMoney(p.purchase_price)],
    ['Постачальник',   p.supplier    || '—'],
    ['Штрих-код',      p.barcode     || '—'],
    ['Залишок',        p.stock_qty <= 0 ? 'Немає' : p.stock_qty + ' шт.' + (p.stock_qty <= p.stock_min ? ' ⚠️ малий' : '')],
    ['Мін. залишок',   p.stock_min + ' шт.'],
    ['Продано всього', p.total_sold > 0 ? p.total_sold + ' шт.' : '—'],
    ['Статус',         p.is_active ? 'Активний' : 'Деактивовано'],
  ];

  document.getElementById('pv-rows').innerHTML = fields.map(([l,v]) => `
    <div style="display:flex;justify-content:space-between;padding:8px 0;
                border-bottom:1px solid var(--border);font-size:14px">
      <span style="color:var(--text-secondary)">${l}</span>
      <span>${esc(String(v))}</span>
    </div>`).join('');

  let actions = '';
  if (state.canWrite) {
    actions = `
      <button class="btn btn-primary btn-sm" style="flex:1 1 calc(50% - 4px)"
        onclick="openSellFromProduct(${p.id})"
        ${p.stock_qty <= 0 ? 'disabled title="Залишок відсутній"' : ''}>&#128722; Продати</button>
      <button class="btn btn-ghost btn-sm"   style="flex:1 1 calc(50% - 4px)" onclick="openArrivalFromProduct(${p.id})">&#128666; Прихід</button>
      <button class="btn btn-ghost btn-sm"   style="flex:1 1 calc(50% - 4px)" onclick="openProductModal(${p.id})">&#9998; Редагувати</button>`;
  }
  if (state.isOwner) {
    if (p.is_active) {
      const canArchive = p.stock_qty <= 0;
      actions += `<button class="btn btn-ghost btn-sm"
        style="flex:1 1 calc(50% - 4px);color:${canArchive ? 'var(--danger)' : 'var(--text-muted)'}"
        onclick="${canArchive ? `toggleProduct(${p.id})` : `toast('Спочатку реалізуйте або спишіть залишок (${p.stock_qty} шт.)','error')`}"
        title="${canArchive ? 'Перенести в архів' : `Залишок ${p.stock_qty} шт. — спочатку обнуліть`}">
        &#128683; В архів${canArchive ? '' : ` (${p.stock_qty} шт.)`}
      </button>`;
    } else {
      actions += `<button class="btn btn-ghost btn-sm" style="flex:1 1 calc(50% - 4px);color:var(--success)"
        onclick="toggleProduct(${p.id})">&#9989; Відновити</button>`;
    }
  }
  document.getElementById('pv-actions').innerHTML = actions;

  openModal('modal-product');
}

function openProductModal(id) {
  state.editProductId = id;
  document.getElementById('modal-product-title').textContent = id ? 'Редагування товару' : 'Новий товар';
  document.getElementById('prod-view-section').style.display = 'none';
  document.getElementById('prod-form-section').style.display = 'block';
  document.getElementById('prod-error').style.display = 'none';

  if (id) {
    const p = state.products.find(x => x.id == id);
    if (p) {
      document.getElementById('pf-name').value     = p.name        || '';
      document.getElementById('pf-category').value = p.category    || '';
      document.getElementById('pf-supplier').value = p.supplier    || '';
      document.getElementById('pf-purchase').value = p.purchase_price || '';
      document.getElementById('pf-sale').value     = p.sale_price   || '';
      document.getElementById('pf-barcode').value  = p.barcode     || '';
      document.getElementById('pf-stock-min').value= p.stock_min   || '0';
      document.getElementById('pf-photo').value    = p.photo_url   || '';
      previewPhoto();
      calcMargin();
    }
  } else {
    ['pf-name','pf-category','pf-supplier','pf-purchase','pf-sale','pf-barcode','pf-photo'].forEach(id => {
      document.getElementById(id).value = '';
    });
    document.getElementById('pf-stock-min').value = '0';
    document.getElementById('margin-hint').textContent = '';
    document.getElementById('pf-photo-preview').innerHTML = '📷';
  }

  openModal('modal-product');
}

function calcMargin() {
  const purchase = parseFloat(document.getElementById('pf-purchase').value) || 0;
  const sale     = parseFloat(document.getElementById('pf-sale').value)     || 0;
  const hint     = document.getElementById('margin-hint');
  if (purchase > 0 && sale > 0) {
    const margin = Math.round((sale - purchase) / sale * 100);
    const profit = (sale - purchase).toFixed(2);
    hint.textContent = `Маржа: ${margin}% · Прибуток: ${profit} грн`;
    hint.style.color = margin >= 0 ? 'var(--success)' : 'var(--danger)';
  } else {
    hint.textContent = '';
  }
}

async function saveProduct() {
  const errEl = document.getElementById('prod-error');
  errEl.style.display = 'none';
  const btn = document.getElementById('btn-save-prod');
  btn.disabled = true; btn.textContent = 'Збереження...';

  const payload = {
    id:             state.editProductId,
    name:           document.getElementById('pf-name').value.trim(),
    category:       document.getElementById('pf-category').value.trim(),
    supplier:       document.getElementById('pf-supplier').value.trim(),
    purchase_price: parseFloat(document.getElementById('pf-purchase').value) || 0,
    sale_price:     parseFloat(document.getElementById('pf-sale').value)     || 0,
    barcode:        document.getElementById('pf-barcode').value.trim(),
    stock_min:      parseInt(document.getElementById('pf-stock-min').value)  || 0,
    photo_url:      document.getElementById('pf-photo').value.trim(),
  };

  const action = state.editProductId ? 'update' : 'create';
  const res    = await api(action, payload, 'products');
  btn.disabled = false; btn.textContent = 'Зберегти';

  if (res.success) {
    closeModal('modal-product');
    toast(state.editProductId ? 'Збережено' : 'Товар додано', 'success');
    loadProducts();
  } else {
    errEl.textContent = res.error; errEl.style.display = 'block';
  }
}

async function toggleProduct(id) {
  const res = await api('toggle_active', { id }, 'products');
  if (res.success) {
    closeModal('modal-product');
    toast(res.message, 'success');
    loadProducts();
  } else toast(res.error, 'error');
}

// ── Прихід ────────────────────────────────────────────────
function openArrivalModal() {
  document.getElementById('arr-error').style.display = 'none';
  document.getElementById('arr-qty').value      = '1';
  document.getElementById('arr-notes').value    = '';
  document.getElementById('arr-total').textContent = '';
  // Скидаємо операцію на arrival
  document.querySelectorAll('.arr-op-btn').forEach(b => b.classList.remove('active'));
  document.querySelector('.arr-op-btn[data-op="arrival"]').classList.add('active');
  document.getElementById('arr-operation').value = 'arrival';
  // Скидаємо статус на paid
  document.querySelectorAll('.arr-status-btn').forEach(b => b.classList.remove('active'));
  document.querySelector('.arr-status-btn[data-status="paid"]').classList.add('active');
  document.getElementById('arr-status').value = 'paid';
  document.getElementById('arr-status-wrap').style.display   = 'block';
  document.getElementById('arr-sale-wrap').style.display     = 'block';
  document.getElementById('arr-purchase-wrap').style.display = 'block';
  document.getElementById('arr-qty-label').textContent = 'Кількість *';
  document.querySelector('#modal-arrival .modal-title').textContent = 'Прихід товару';
  openModal('modal-arrival');
}

function onArrProductChange() {
  const sel = document.getElementById('arr-product');
  const opt = sel.options[sel.selectedIndex];
  if (!opt?.value) return;
  document.getElementById('arr-purchase').value = opt.dataset.purchase || '';
  document.getElementById('arr-sale').value     = opt.dataset.sale     || '';
  document.getElementById('arr-supplier').value = opt.dataset.supplier || '';
  calcArrTotal();
}

async function submitArrival() {
  const errEl = document.getElementById('arr-error');
  errEl.style.display = 'none';
  const btn = document.getElementById('btn-arr-submit');
  btn.disabled = true; btn.textContent = 'Збереження...';

  const operation = document.getElementById('arr-operation').value;
  const status    = document.getElementById('arr-status').value;
  const qty       = parseInt(document.getElementById('arr-qty').value) || 0;

  const res = await api('add_arrival', {
    product_id:     parseInt(document.getElementById('arr-product').value) || 0,
    quantity:       qty,
    operation,
    status,
    expected_qty:   status === 'pending' ? qty : null,
    purchase_price: parseFloat(document.getElementById('arr-purchase').value) || 0,
    sale_price:     parseFloat(document.getElementById('arr-sale').value)     || 0,
    supplier:       document.getElementById('arr-supplier').value.trim(),
    notes:          document.getElementById('arr-notes').value.trim(),
  }, 'products');

  btn.disabled = false; btn.textContent = 'Підтвердити';

  if (res.success) {
    closeModal('modal-arrival');
    toast(res.message, 'success');
    loadProducts();
    if (document.getElementById('tab-arrivals').classList.contains('active')) loadArrivals();
  } else {
    errEl.textContent = res.error; errEl.style.display = 'block';
  }
}

// ── Продаж ────────────────────────────────────────────────
function openSellModal() {
  state.sellClientId = null;
  document.getElementById('sell-error').style.display = 'none';
  document.getElementById('sell-client-badge').style.display = 'none';
  document.getElementById('sell-client-input').value = '';
  document.getElementById('sell-qty').value      = '1';
  document.getElementById('sell-discount').value = '0';
  document.getElementById('sell-summary').style.display = 'none';
  openModal('modal-sell');
}

function onSellProductChange() {
  const sel = document.getElementById('sell-product');
  const opt = sel.options[sel.selectedIndex];
  if (!opt?.value) { document.getElementById('sell-stock-info').textContent = ''; return; }
  document.getElementById('sell-price').value = opt.dataset.sale || '';
  document.getElementById('sell-stock-info').textContent =
    `Залишок: ${opt.dataset.stock} шт.`;
  recalcSell();
}

function adjQty(delta) {
  const inp = document.getElementById('sell-qty');
  inp.value = Math.max(1, (parseInt(inp.value) || 1) + delta);
  recalcSell();
}

function recalcSell() {
  const qty      = parseInt(document.getElementById('sell-qty').value)      || 1;
  const price    = parseFloat(document.getElementById('sell-price').value)   || 0;
  const discount = parseFloat(document.getElementById('sell-discount').value)|| 0;
  const total    = Math.max(0, qty * price - discount);

  const sel  = document.getElementById('sell-product');
  const opt  = sel.options[sel.selectedIndex];
  const cost = parseFloat(opt?.dataset?.purchase || 0);
  const profit = total - cost * qty;

  document.getElementById('ss-subtotal').textContent = formatMoney(qty * price);
  document.getElementById('ss-total').textContent    = formatMoney(total);
  document.getElementById('ss-profit').textContent   = formatMoney(profit);
  document.getElementById('ss-disc-row').style.display = discount > 0 ? 'flex' : 'none';
  document.getElementById('ss-disc').textContent     = '−' + formatMoney(discount);
  document.getElementById('sell-summary').style.display = price > 0 ? 'block' : 'none';
}

let sellClientTimer = null;
function searchSellClient() {
  clearTimeout(sellClientTimer);
  const q = document.getElementById('sell-client-input').value.trim();
  if (q.length < 2) { document.getElementById('sell-client-dd').style.display='none'; return; }
  sellClientTimer = setTimeout(async () => {
    const res = await api('search', { q }, 'clients');
    const dd = document.getElementById('sell-client-dd');
    if (!res.success || !res.results?.length) { dd.style.display='none'; return; }
    dd.innerHTML = res.results.map(c => `
      <div onclick="selectSellClient(${c.id},'${esc(c.full_name)}')"
           style="padding:9px 12px;cursor:pointer;font-size:14px;border-bottom:1px solid var(--border)"
           onmouseover="this.style.background='var(--bg-hover)'"
           onmouseout="this.style.background=''">
        <strong>${esc(c.full_name)}</strong>
        <span style="color:var(--text-muted);font-size:12px;margin-left:8px">${c.phone||''}</span>
      </div>`).join('');
    dd.style.display = 'block';
  }, 300);
}

function selectSellClient(id, name) {
  state.sellClientId = id;
  document.getElementById('sell-client-input').value = '';
  document.getElementById('sell-client-dd').style.display = 'none';
  document.getElementById('sell-client-badge').style.display = 'block';
  document.getElementById('sell-client-name').textContent = name;
}

function clearSellClient() {
  state.sellClientId = null;
  document.getElementById('sell-client-badge').style.display = 'none';
}

async function submitSell() {
  const errEl = document.getElementById('sell-error');
  errEl.style.display = 'none';
  const btn = document.getElementById('btn-sell-submit');

  const productId = parseInt(document.getElementById('sell-product').value) || 0;
  const method    = document.getElementById('sell-method').value;

  if (!productId) {
    errEl.textContent = 'Оберіть товар';
    errEl.style.display = 'block';
    return;
  }
  if (method === 'deposit' && !state.sellClientId) {
    errEl.textContent = 'При оплаті депозитом клієнт обов\'язковий';
    errEl.style.display = 'block';
    return;
  }

  btn.disabled = true; btn.textContent = 'Продаємо...';

  const payload = {
    product_id:     productId,
    quantity:       parseInt(document.getElementById('sell-qty').value)        || 1,
    sale_price:     parseFloat(document.getElementById('sell-price').value)    || 0,
    discount:       parseFloat(document.getElementById('sell-discount').value) || 0,
    payment_method: method,
    client_id:      state.sellClientId || null,
    client_name:    state.sellClientId ? document.getElementById('sell-client-name').textContent : null,
  };

  let res;
  try {
    const r = await fetch('/api/sell_api.php', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const text = await r.text();
    res = JSON.parse(text);
  } catch(e) {
    res = { success: false, error: 'Помилка мережі: ' + e.message };
  }

  btn.disabled = false; btn.textContent = 'Продати';

  if (res.success) {
    closeModal('modal-sell');
    toast(res.message, 'success');
    loadProducts();
    if (document.getElementById('tab-sales').classList.contains('active')) loadSales();
  } else {
    errEl.textContent = res.error || 'Невідома помилка';
    errEl.style.display = 'block';
  }
}

// ── Журнали ───────────────────────────────────────────────
async function loadSales() {
  const tbody = document.getElementById('sales-tbody');
  tbody.innerHTML = '<tr><td colspan="7"><div class="loader"><div class="spinner"></div></div></td></tr>';

  const res = await api('get_sales', {
    date_from: document.getElementById('sales-from').value,
    date_to:   document.getElementById('sales-to').value,
  }, 'products');

  if (!res.success) return;

  const { sales, summary } = res;

  // Підсумок
  if (summary) {
    document.getElementById('sales-summary').style.display = 'block';
    document.getElementById('sales-summary').innerHTML = `
      <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:14px;padding:12px 16px;
                  background:var(--bg-elevated);border-radius:var(--radius-sm)">
        <span>Продажів: <strong>${summary.cnt || 0}</strong></span>
        <span>Виручка: <strong style="color:var(--accent)">${formatMoney(summary.revenue || 0)}</strong></span>
        <span>Прибуток: <strong style="color:var(--success)">${formatMoney(summary.profit || 0)}</strong></span>
      </div>`;
  }

  tbody.innerHTML = sales.length
    ? sales.map(s => `
        <tr>
          <td>
            <div class="log-row-product">${esc(s.product_name)}</div>
            <div class="log-row-meta">${esc(s.category||'—')}</div>
          </td>
          <td>${s.quantity}</td>
          <td>
            <div style="font-weight:500">${formatMoney(s.total_amount)}</div>
            ${parseFloat(s.discount)>0 ? `<div style="font-size:11px;color:var(--success)">знижка ${formatMoney(s.discount)}</div>` : ''}
          </td>
          <td style="font-size:13px">${s.client_name ? esc(s.client_name) : '—'}</td>
          <td><span class="badge badge-info" style="font-size:10px">${payLabel(s.payment_method)}</span></td>
          <td style="font-size:13px;color:var(--text-secondary)">${s.admin_name||'—'}</td>
          <td style="font-size:13px;color:var(--text-secondary)">${formatDate(s.created_at)}</td>
        </tr>`).join('')
    : '<tr><td colspan="7"><div class="empty-state" style="padding:30px"><p>Продажів за цей період немає</p></div></td></tr>';
}

async function loadArrivals() {
  const tbody = document.getElementById('arrivals-tbody');
  tbody.innerHTML = '<tr><td colspan="9"><div class="loader"><div class="spinner"></div></div></td></tr>';

  const res = await api('get_arrivals', {
    date_from: document.getElementById('arr-from').value,
    date_to:   document.getElementById('arr-to').value,
  }, 'products');

  if (!res.success) return;

  const opLabels = {
    arrival:'Прихід', overdue:'Прострочка', repack:'Розфасування', transfer:'Перенесення'
  };
  const opColors = {
    arrival:'var(--success)', overdue:'var(--danger)', repack:'var(--warning)', transfer:'var(--text-muted)'
  };
  const statusBadge = {
    paid:    '<span class="badge badge-active" style="font-size:10px">Оплачено</span>',
    unpaid:  '<span class="badge badge-inactive" style="font-size:10px;background:rgba(248,113,113,.15);color:var(--danger)">&#128308; Не оплачено</span>',
    pending: '<span class="badge badge-pending" style="font-size:10px">&#9201; Замовлено</span>',
  };

  tbody.innerHTML = res.arrivals.length
    ? res.arrivals.map(a => {
        const isMinus  = ['overdue','repack','transfer'].includes(a.operation);
        const isPending = a.status === 'pending';
        const qtyStr   = isPending
          ? `<span style="color:var(--text-muted)">${a.expected_qty || a.quantity} (очік.)</span>`
          : `<span style="color:${isMinus?'var(--danger)':'var(--success)'}">${isMinus?'−':'+'}${a.quantity}</span>`;
        const sumStr   = isPending ? '—'
          : `<span style="color:${isMinus?'var(--danger)':'inherit'}">${isMinus?'−':''}${formatMoney(Math.abs(a.total_cost))}</span>`;
        return `
        <tr>
          <td>
            <div class="log-row-product">${esc(a.product_name)}</div>
            <div class="log-row-meta">${esc(a.category||'—')}</div>
          </td>
          <td><span style="font-size:13px;font-weight:500;color:${opColors[a.operation]||'inherit'}">${opLabels[a.operation]||a.operation}</span></td>
          <td>${a.operation==='arrival' ? (statusBadge[a.status]||'—') : '—'}</td>
          <td>${qtyStr}</td>
          <td style="font-size:13px">${isPending?'—':formatMoney(a.purchase_price)}</td>
          <td style="font-weight:500">${sumStr}</td>
          <td style="font-size:13px">${esc(a.supplier||'—')}</td>
          <td style="font-size:13px;color:var(--text-secondary)">${a.admin_name||'—'}</td>
          <td style="font-size:13px;color:var(--text-secondary)">${formatDate(a.created_at)}</td>
        </tr>`;
      }).join('')
    : '<tr><td colspan="9"><div class="empty-state" style="padding:30px"><p>Операцій за цей період немає</p></div></td></tr>';
}

// ── Форма приходу: вибір операції ─────────────────────────
function selectArrOp(btn) {
  document.querySelectorAll('.arr-op-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  const op = btn.dataset.op;
  document.getElementById('arr-operation').value = op;

  // Статус і ціни — лише для arrival
  const isArrival = op === 'arrival';
  document.getElementById('arr-status-wrap').style.display    = isArrival ? 'block' : 'none';
  document.getElementById('arr-purchase-wrap').style.display  = 'block';
  document.getElementById('arr-sale-wrap').style.display      = isArrival ? 'block' : 'none';

  // При мінус-операціях скидаємо статус
  if (!isArrival) {
    document.getElementById('arr-status').value = 'paid';
    document.querySelectorAll('.arr-status-btn').forEach(b => b.classList.remove('active'));
    document.querySelector('.arr-status-btn[data-status="paid"]').classList.add('active');
  }

  document.getElementById('modal-product-title') // не міняємо
  document.querySelector('#modal-arrival .modal-title').textContent =
    { arrival:'Прихід товару', overdue:'Прострочка', repack:'Розфасування', transfer:'Перенесення склад' }[op];

  calcArrTotal();
}

// ── Форма приходу: вибір статусу ──────────────────────────
function selectArrStatus(btn) {
  document.querySelectorAll('.arr-status-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  const status = btn.dataset.status;
  document.getElementById('arr-status').value = status;
  const isPending = status === 'pending';
  document.getElementById('arr-qty-label').textContent       = isPending ? 'Очікувана к-сть *' : 'Кількість *';
  // При pending ціна закупки залишається (товар може бути оплачений), ціна продажу ховається
  document.getElementById('arr-purchase-wrap').style.display = 'block';
  document.getElementById('arr-sale-wrap').style.display     = isPending ? 'none' : 'block';
  document.getElementById('arr-total').textContent           = isPending ? 'Товар буде зафіксовано як замовлений, на склад не додається' : '';
  calcArrTotal();
}

function calcArrTotal() {
  const qty      = parseInt(document.getElementById('arr-qty').value)      || 0;
  const purchase = parseFloat(document.getElementById('arr-purchase').value) || 0;
  const status   = document.getElementById('arr-status').value;
  const op       = document.getElementById('arr-operation').value;
  if (status === 'pending' || !purchase || !qty) return;
  const total   = purchase * qty;
  const isMinus = ['overdue','repack','transfer'].includes(op);
  document.getElementById('arr-total').textContent =
    `Сума: ${isMinus?'−':''}${formatMoney(total)}`;
}

// ── Вкладки ───────────────────────────────────────────────
function switchTab(tab, btn) {
  document.querySelectorAll('.prod-tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.prod-tab-content').forEach(c => {
    c.classList.remove('active'); c.style.display = 'none';
  });
  btn.classList.add('active');
  const el = document.getElementById('tab-' + tab);
  el.classList.add('active'); el.style.display = 'block';

  if (tab === 'sales')    loadSales();
  if (tab === 'arrivals') loadArrivals();
  if (tab === 'archive')  loadArchive();
}

// ── Архів ────────────────────────────────────────────────
async function loadArchive() {
  const grid = document.getElementById('archive-grid');
  grid.innerHTML = '<div style="grid-column:1/-1"><div class="loader"><div class="spinner"></div></div></div>';
  const res = await api('get_list', { inactive: true }, 'products');
  if (!res.success) { grid.innerHTML = `<div style="grid-column:1/-1"><div class="alert alert-error">${res.error}</div></div>`; return; }
  const archived = (res.products || []).filter(p => !p.is_active);
  if (!archived.length) {
    grid.innerHTML = '<div style="grid-column:1/-1" class="empty-state" style="padding:40px"><div style="font-size:40px">&#128452;</div><p>Архів порожній</p></div>';
    return;
  }
  grid.innerHTML = archived.map(p => `
    <div class="product-card inactive" style="position:relative">
      <div class="product-img">
        ${p.photo_url ? `<img src="${esc(p.photo_url)}" onerror="this.parentNode.innerHTML='&#128230;'">` : '&#128230;'}
      </div>
      <div class="product-body">
        <div class="product-name" title="${esc(p.name)}">${esc(p.name)}</div>
        <div class="product-price">${p.sale_price} грн</div>
        <div class="product-meta">
          <span class="product-category">${esc(p.category || '—')}</span>
          <span class="stock-badge out">${p.stock_qty} шт.</span>
        </div>
        ${state.isOwner ? `<button class="btn btn-ghost btn-sm" style="margin-top:8px;width:100%;color:var(--success)"
          onclick="restoreProduct(${p.id})">&#9989; Відновити</button>` : ''}
      </div>
    </div>`).join('');
}

async function restoreProduct(id) {
  const res = await api('toggle_active', { id }, 'products');
  if (res.success) { toast('Товар відновлено', 'success'); loadArchive(); }
  else toast(res.error, 'error');
}

// ── Превью фото ───────────────────────────────────────────
function previewPhoto() {
  const url = document.getElementById('pf-photo').value.trim();
  const box = document.getElementById('pf-photo-preview');
  if (!url) { box.innerHTML = '📷'; return; }
  box.innerHTML = `<img src="${esc(url)}" style="width:100%;height:100%;object-fit:contain;padding:4px"
    onerror="this.parentNode.innerHTML='❌'">`;
}

// ── Відкрити продаж з картки товару ──────────────────────
function openSellFromProduct(id) {
  closeModal('modal-product');
  openSellModal();
  setTimeout(() => {
    const sel = document.getElementById('sell-product');
    if (sel) { sel.value = id; onSellProductChange(); }
  }, 100);
}

// ── Відкрити прихід з картки товару ──────────────────────
function openArrivalFromProduct(id) {
  closeModal('modal-product');
  openArrivalModal();
  setTimeout(() => {
    const sel = document.getElementById('arr-product');
    if (sel) { sel.value = id; onArrProductChange(); }
  }, 100);
}

// ── Зміна методу оплати — клієнт обов'язковий при депозиті
function onSellMethodChange() {
  const isDeposit = document.getElementById('sell-method').value === 'deposit';
  document.getElementById('sell-client-required').style.display = isDeposit ? 'inline' : 'none';
}

// ── Утиліти ───────────────────────────────────────────────
function payLabel(m) {
  return { cash:'Готівка', card:'Карта', terminal:'Термінал',
           deposit:'Депозит', other:'Інше' }[m] || m || '—';
}
function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
