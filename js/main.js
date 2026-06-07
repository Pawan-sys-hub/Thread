// =============================================
// TrendTrack V2 — main.js (Global Utilities)
// =============================================

const API = {
  base: '/TrendTrackV2/php',
  get trends() { return `${this.base}/api/trends.php`; },
  get product() { return `${this.base}/api/product.php`; },
  get categories() { return `${this.base}/api/categories.php`; },
  get cart() { return `${this.base}/api/cart.php`; },
  get wishlist() { return `${this.base}/api/wishlist.php`; },
  get order() { return `${this.base}/api/order.php`; },
  get profile() { return `${this.base}/api/profile.php`; },
  get session() { return `${this.base}/api/session.php`; },
  get esewa() { return `${this.base}/api/esewa.php`; },
  get login() { return `${this.base}/auth/login.php`; },
  get register() { return `${this.base}/auth/register.php`; },
  get logout() { return `${this.base}/auth/logout.php`; },
  // Admin
  get adminDashboard() { return `${this.base}/api/admin/dashboard.php`; },
  get adminProducts() { return `${this.base}/api/admin/products.php`; },
  get adminCategories() { return `${this.base}/api/admin/categories.php`; },
  get adminOrders() { return `${this.base}/api/admin/orders.php`; },
  get adminUsers() { return `${this.base}/api/admin/users.php`; },
};

// ---- HTTP Helpers ----
async function apiFetch(url, options = {}) {
  try {
    const resp = await fetch(url, {
      headers: { 'Content-Type': 'application/json', ...options.headers },
      credentials: 'same-origin',
      ...options,
    });
    if (!resp.ok && resp.status !== 400 && resp.status !== 401 && resp.status !== 403 && resp.status !== 404 && resp.status !== 409) {
      const text = await resp.text();
      console.error('Non-OK response:', resp.status, text);
      return { success: false, error: `Server error (${resp.status}). Please try again.` };
    }
    const data = await resp.json();
    return data;
  } catch (e) {
    console.error('API fetch error:', e);
    return { success: false, error: 'Cannot connect to server. Make sure the server is running.' };
  }
}
const get = (url) => apiFetch(url);
const post = (url, body) => apiFetch(url, { method: 'POST', body: JSON.stringify(body) });
const put = (url, body) => apiFetch(url, { method: 'PUT', body: JSON.stringify(body) });
const del = (url, body) => apiFetch(url, { method: 'DELETE', body: JSON.stringify(body) });

// ---- Session / Auth ----
let _session = null;
async function getSession(force = false) {
  if (!force && _session !== null) return _session;
  const data = await get(API.session);
  _session = data.logged_in ? data : { logged_in: false, user: null, role: null };
  return _session;
}

async function requireAuth() {
  const s = await getSession();
  if (!s.logged_in) { window.location.href = '/TrendTrackV2/frontend/login.html?redirect=' + encodeURIComponent(window.location.href); return null; }
  return s.user;
}

async function requireCustomer() {
  const s = await getSession();
  if (!s.logged_in) { window.location.href = '/TrendTrackV2/frontend/login.html'; return null; }
  if (s.role === 'admin') { window.location.href = '/TrendTrackV2/frontend/admin/index.html'; return null; }
  return s.user;
}

function clearSession() { _session = null; }

// ---- Navbar ----
const NAV_LINKS = [
  { href: '/TrendTrackV2/frontend/index.html', label: 'Home' },
  { href: '/TrendTrackV2/frontend/trends.html', label: 'All Fashion' },
  { href: '/TrendTrackV2/frontend/trends.html?category=men', label: 'Men' },
  { href: '/TrendTrackV2/frontend/trends.html?category=women', label: 'Women' },
  { href: '/TrendTrackV2/frontend/trends.html?category=accessories', label: 'Accessories' },
  { href: '/TrendTrackV2/frontend/trends.html?trending=1', label: '🔥 Hot', hot: true },
];

async function buildNavbar() {
  const s = await getSession();
  const logged = s.logged_in;
  const name = s.user?.name ?? '';
  const role = s.role;

  const cartCount = (logged && role !== 'admin') ? await fetchCartCount() : 0;
  const wishCount = (logged && role !== 'admin') ? await fetchWishCount() : 0;

  const adminLink = role === 'admin'
    ? `<a href="/TrendTrackV2/frontend/admin/index.html" class="btn-admin"><span>🛡️</span> Admin</a>`
    : '';

  const html = `
  <nav class="navbar" id="navbar">
    <div class="container navbar-inner">
      <a href="/TrendTrackV2/frontend/index.html" class="navbar-logo">
        <span class="navbar-logo-icon">T</span>
        <span class="navbar-logo-text">TrendTrack</span>
      </a>
      <div class="navbar-nav">
        ${NAV_LINKS.map(l => `<a href="${l.href}" class="nav-link${l.hot ? ' nav-link-hot' : ''}">${l.label}</a>`).join('')}
      </div>
      <div class="navbar-search" id="navSearchWrap">
        <span class="navbar-search-icon">🔍</span>
        <input type="text" id="navSearchInput" placeholder="Search styles…" autocomplete="off">
      </div>
      <div class="navbar-actions">
        ${role !== 'admin' ? `
        <a href="/TrendTrackV2/frontend/wishlist.html" class="icon-btn" title="Wishlist">
          ♡ ${wishCount > 0 ? `<span class="badge">${wishCount}</span>` : ''}
        </a>
        <a href="/TrendTrackV2/frontend/cart.html" class="icon-btn" title="Cart">
          🛒 ${cartCount > 0 ? `<span class="badge">${cartCount}</span>` : ''}
        </a>` : ''}
        ${adminLink}
        ${logged
      ? `<a href="/TrendTrackV2/frontend/profile.html" class="btn btn-auth btn-login nav-user-btn">
             <span class="nav-user-avatar">${name.charAt(0).toUpperCase()}</span>
             ${name.split(' ')[0]}
           </a>
           <button onclick="handleLogout()" class="btn btn-auth btn-signup">Logout</button>`
      : `<a href="/TrendTrackV2/frontend/login.html" class="btn btn-auth btn-login">Login</a>
           <a href="/TrendTrackV2/frontend/register.html" class="btn btn-auth btn-signup">Sign Up</a>`
    }
      </div>
      <button class="hamburger" id="hamburger" aria-label="Menu">
        <span></span><span></span><span></span>
      </button>
    </div>
  </nav>
  <div class="mobile-menu" id="mobileMenu">
    ${NAV_LINKS.map(l => `<a href="${l.href}">${l.label}</a>`).join('')}
    ${role !== 'admin' ? `
    <div class="mobile-menu-divider"></div>
    <a href="/TrendTrackV2/frontend/wishlist.html">♡ Wishlist ${wishCount ? `(${wishCount})` : ''}</a>
    <a href="/TrendTrackV2/frontend/cart.html">🛒 Cart ${cartCount ? `(${cartCount})` : ''}</a>` : ''}
    <div class="mobile-menu-divider"></div>
    ${logged
      ? `${role === 'admin' ? `<a href="/TrendTrackV2/frontend/admin/index.html">🛡️ Admin Panel</a>` : ''}
         <a href="/TrendTrackV2/frontend/profile.html">👤 My Profile</a>
         <a href="#" onclick="handleLogout()">🚪 Logout</a>`
      : `<a href="/TrendTrackV2/frontend/login.html">Login</a>
         <a href="/TrendTrackV2/frontend/register.html">Sign Up</a>`}
  </div>`;

  document.body.insertAdjacentHTML('afterbegin', html);
  highlightActiveNav();

  document.getElementById('hamburger').addEventListener('click', () => {
    const m = document.getElementById('mobileMenu');
    m.classList.toggle('open');
    document.getElementById('hamburger').classList.toggle('open');
  });

  const navSearch = document.getElementById('navSearchInput');
  if (navSearch) {
    navSearch.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && e.target.value.trim()) {
        window.location.href = `/TrendTrackV2/frontend/trends.html?search=${encodeURIComponent(e.target.value.trim())}`;
      }
    });
  }

  window.addEventListener('scroll', () => {
    document.getElementById('navbar')?.classList.toggle('scrolled', window.scrollY > 20);
  });
}

function highlightActiveNav() {
  const path = window.location.pathname + window.location.search;
  document.querySelectorAll('.nav-link').forEach(a => {
    const href = a.getAttribute('href');
    if (href === path || (href !== '/TrendTrackV2/frontend/index.html' && path.includes(href.split('?')[0]) && href.includes('?') && path.includes(href.split('?')[1]))) {
      a.classList.add('active');
    }
  });
}



async function fetchCartCount() {
  try { const d = await get(API.cart); return d.success ? (d.count || 0) : 0; } catch { return 0; }
}
async function fetchWishCount() {
  try { const d = await get(API.wishlist); return d.success ? (d.items?.length || 0) : 0; } catch { return 0; }
}

async function handleLogout() {
  await post(API.logout, {});
  clearSession();
  window.location.href = '/TrendTrackV2/frontend/index.html';
}

// ---- Footer ----
function buildFooter() {
  const html = `
  <footer>
    <div class="container">
      <div class="footer-grid">
        <div>
          <div class="footer-brand"><span style="font-size:1.4rem">👜</span> TrendTrack</div>
          <p class="footer-desc">Nepal's premier fashion destination. Discover men's, women's and accessories trends before everyone else.</p>
        </div>
        <div>
          <p class="footer-heading">Shop</p>
          <div class="footer-links">
            <a href="/TrendTrackV2/frontend/trends.html?category=men">Men's Fashion</a>
            <a href="/TrendTrackV2/frontend/trends.html?category=women">Women's Fashion</a>
            <a href="/TrendTrackV2/frontend/trends.html?category=accessories">Accessories</a>
            <a href="/TrendTrackV2/frontend/trends.html?trending=1">🔥 Hot Right Now</a>
          </div>
        </div>
        <div>
          <p class="footer-heading">Account</p>
          <div class="footer-links">
            <a href="/TrendTrackV2/frontend/profile.html">My Profile</a>
            <a href="/TrendTrackV2/frontend/orders.html">My Orders</a>
            <a href="/TrendTrackV2/frontend/wishlist.html">Wishlist</a>
            <a href="/TrendTrackV2/frontend/cart.html">Cart</a>
          </div>
        </div>
        <div>
          <p class="footer-heading">Company</p>
          <div class="footer-links">
            <a href="#">About TrendTrack</a>
            <a href="#">Privacy Policy</a>
            <a href="#">Terms of Service</a>
            <a href="#">Contact Us</a>
          </div>
          <div style="margin-top:20px;padding:12px;background:rgba(255,255,255,0.06);border-radius:8px">
            <p style="color:rgba(255,255,255,0.5);font-size:0.75rem;margin-bottom:6px">💳 We accept</p>
            <div style="display:flex;gap:8px;align-items:center">
              <span style="background:#60bb46;color:white;font-size:0.7rem;font-weight:700;padding:3px 8px;border-radius:4px">eSewa</span>
              <span style="color:rgba(255,255,255,0.5);font-size:0.75rem">Cash on Delivery</span>
            </div>
          </div>
        </div>
      </div>
      <div class="footer-bottom">
        <p>© ${new Date().getFullYear()} TrendTrack. All rights reserved. Made for Nepal 🇳🇵</p>
        <div class="social-links">
          <a class="social-link" href="#" title="Facebook">𝐟</a>
          <a class="social-link" href="#" title="Instagram">📷</a>
          <a class="social-link" href="#" title="TikTok">♪</a>
        </div>
      </div>
    </div>
  </footer>`;
  document.body.insertAdjacentHTML('beforeend', html);
}

// ---- Toast ----
function showToast(message, type = 'success', duration = 3500) {
  let container = document.querySelector('.toast-container');
  if (!container) {
    container = document.createElement('div');
    container.className = 'toast-container';
    document.body.appendChild(container);
  }
  const toast = document.createElement('div');
  toast.className = `toast ${type}`;
  toast.innerHTML = `<span>${message}</span>`;
  container.appendChild(toast);
  setTimeout(() => { toast.classList.add('exit'); setTimeout(() => toast.remove(), 300); }, duration);
}

// ---- Product Card (with Buy Now + Add to Cart) ----
function productCard(p, wishlisted = false) {
  const discount = p.original_price ? Math.round((1 - p.price / p.original_price) * 100) : null;
  const badge = p.badge ? `<span class="product-badge badge-${p.badge.toLowerCase().replace(/\s+/g, '-')}">${p.badge}</span>` : '';
  const hotBadge = p.is_hot ? `<span class="hot-badge">🔥 Hot</span>` : '';
  const trendBadge = p.is_trending && !p.is_hot ? `<span class="trend-badge">📈 Trending</span>` : '';
  return `
  <div class="product-card" data-id="${p.id}">
    <div class="product-card-image" onclick="window.location.href='/TrendTrackV2/frontend/product.html?slug=${p.slug}'">
      <img src="${p.image_url || 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=600'}"
           alt="${p.name}" loading="lazy"
           onerror="this.src='https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=600'">
      ${hotBadge}${trendBadge}
      ${badge}
      <button class="wishlist-btn ${wishlisted || p._wishlisted ? 'active' : ''}"
        onclick="event.stopPropagation(); toggleWishlist(${p.id}, this)"
        data-id="${p.id}" title="Wishlist">♡</button>
      <div class="product-card-overlay">
        <button class="quick-btn cart-btn" onclick="event.stopPropagation(); quickAddToCart(${p.id})">🛒 Add to Cart</button>
        <button class="quick-btn buy-btn" onclick="event.stopPropagation(); quickBuyNow(${p.id}, '${p.slug}')">⚡ Buy Now</button>
      </div>
    </div>
    <div class="product-card-body" onclick="window.location.href='/TrendTrackV2/frontend/product.html?slug=${p.slug}'">
      <p class="product-category">${p.category_name || ''}</p>
      <h3 class="product-name">${p.name}</h3>
      <div class="product-price">
        <span class="price-current">रू ${Number(p.price).toLocaleString('ne-NP')}</span>
        ${p.original_price ? `<span class="price-original">रू ${Number(p.original_price).toLocaleString('ne-NP')}</span>` : ''}
        ${discount ? `<span class="price-discount">-${discount}%</span>` : ''}
      </div>
    </div>
  </div>`;
}

// ---- Status Badge ----
function statusBadge(status) {
  const labels = { pending: 'Pending', processing: 'Processing', shipped: 'Shipped', delivered: 'Delivered', cancelled: 'Cancelled', paid: 'Paid', unpaid: 'Unpaid', failed: 'Failed', active: 'Active', inactive: 'Inactive' };
  return `<span class="status-badge status-${status}">${labels[status] || status}</span>`;
}

// ---- Format Currency ----
function formatNPR(amount) {
  return `रू ${Number(amount).toLocaleString('ne-NP', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

// ---- Format Date ----
function formatDate(dateStr) {
  return new Date(dateStr).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

// ---- Quick Add to Cart ----
async function quickAddToCart(productId) {
  const s = await getSession();
  if (!s.logged_in) {
    showToast('Please login to add to cart.', 'warning');
    setTimeout(() => { window.location.href = '/TrendTrackV2/frontend/login.html'; }, 1000);
    return;
  }
  if (s.role === 'admin') { showToast('Admins cannot shop.', 'error'); return; }
  const r = await post(API.cart, { product_id: productId, quantity: 1 });
  if (r.success) {
    showToast('Added to cart! 🛒');
    // Update badge
    const badges = document.querySelectorAll('.icon-btn .badge');
    badges.forEach(b => { if (b.closest('a[href*="cart"]')) { b.textContent = parseInt(b.textContent || 0) + 1; } });
  } else {
    showToast(r.error || 'Failed to add to cart.', 'error');
  }
}

// ---- Quick Buy Now ----
async function quickBuyNow(productId, slug) {
  const s = await getSession();
  if (!s.logged_in) {
    showToast('Please login to buy.', 'warning');
    setTimeout(() => { window.location.href = `/TrendTrackV2/frontend/login.html?redirect=/TrendTrackV2/frontend/product.html?slug=${slug}`; }, 1000);
    return;
  }
  if (s.role === 'admin') { showToast('Admins cannot shop.', 'error'); return; }
  window.location.href = `/TrendTrackV2/frontend/checkout.html?buy_now=${productId}&qty=1`;
}

// ---- Toggle Wishlist ----
async function toggleWishlist(productId, btn) {
  const s = await getSession();
  if (!s.logged_in) {
    showToast('Please login to save to wishlist.', 'warning');
    setTimeout(() => { window.location.href = '/TrendTrackV2/frontend/login.html'; }, 1000);
    return;
  }
  if (s.role === 'admin') { showToast('Admins cannot use wishlist.', 'error'); return; }
  const isActive = btn.classList.contains('active');
  const r = isActive
    ? await del(API.wishlist, { product_id: productId })
    : await post(API.wishlist, { product_id: productId });
  if (r.success) {
    btn.classList.toggle('active');
    showToast(isActive ? 'Removed from wishlist' : 'Saved to wishlist! ♡');
  } else {
    showToast(r.error || 'Failed.', 'error');
  }
}

// ---- Skeleton Loader ----
function skeletonGrid(count = 8, cols = 4) {
  return `<div class="loading-grid" style="grid-template-columns:repeat(${cols},1fr)">
    ${'<div class="skeleton-card"><div class="skeleton skeleton-img"></div><div class="skeleton-body"><div class="skeleton skeleton-line"></div><div class="skeleton skeleton-line"></div><div class="skeleton skeleton-line"></div></div></div>'.repeat(count)}
  </div>`;
}

// ---- eSewa Payment Initiation ----
async function initiateEsewaPayment(orderId, amount) {
  showToast('Connecting to eSewa…', 'info');
  const r = await post(`${API.esewa}?action=initiate`, { order_id: orderId, amount: amount });
  if (!r.success) { showToast(r.error || 'eSewa initiation failed.', 'error'); return; }

  // Build and submit eSewa form
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = r.pay_url;
  const fields = {
    amt: r.amount,
    txAmt: r.tax_amount,
    psc: r.service_charge,
    pdc: r.delivery_charge,
    tAmt: r.total_amount,
    pid: r.product_id,
    scd: r.merchant_code,
    su: r.success_url,
    fu: r.failure_url,
  };
  Object.entries(fields).forEach(([k, v]) => {
    const inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = k; inp.value = v;
    form.appendChild(inp);
  });
  document.body.appendChild(form);
  form.submit();
}

// ---- Init ----
document.addEventListener('DOMContentLoaded', async () => {
  if (document.body.classList.contains('admin-page') || window.location.pathname.includes('/frontend/admin/')) {
    document.dispatchEvent(new Event('appReady'));
    return;
  }

  await buildNavbar();
  buildFooter();
  document.dispatchEvent(new Event('appReady'));
});
