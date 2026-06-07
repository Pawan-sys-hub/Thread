// =============================================
// TrendTrack V2 — admin.js (Admin Shared Utilities)
// =============================================

// Guard: redirect non-admins
async function requireAdmin() {
  const s = await getSession();
  if (!s.logged_in) { window.location.href = '/TrendTrackV2/frontend/login.html'; return null; }
  if (s.role !== 'admin') { window.location.href = '/TrendTrackV2/frontend/index.html'; return null; }
  return s.user;
}

// ---- Admin Topbar & Sidebar ----
function buildAdminLayout(pageTitle, pageSubtitle = '') {
  const sidebar = `
  <aside class="admin-sidebar" id="adminSidebar">
    <div class="admin-sidebar-logo">
      <div class="admin-logo-icon">T</div>
      <div class="admin-logo-text">
        <span class="admin-logo-brand">TrendTrack</span>
        <span class="admin-logo-sub">Admin Panel</span>
      </div>
    </div>
    <nav class="admin-nav">
      <span class="admin-nav-group">Overview</span>
      <a href="/TrendTrackV2/frontend/admin/index.html" class="admin-nav-link" data-page="dashboard">
        <span class="nav-icon">📊</span> Dashboard
      </a>
      <span class="admin-nav-group">Catalog</span>
      <a href="/TrendTrackV2/frontend/admin/products.html" class="admin-nav-link" data-page="products">
        <span class="nav-icon">📦</span> Products
      </a>
      <a href="/TrendTrackV2/frontend/admin/categories.html" class="admin-nav-link" data-page="categories">
        <span class="nav-icon">🗂️</span> Categories
      </a>
      <span class="admin-nav-group">Sales</span>
      <a href="/TrendTrackV2/frontend/admin/orders.html" class="admin-nav-link" data-page="orders">
        <span class="nav-icon">🚚</span> Orders
      </a>
      <span class="admin-nav-group">People</span>
      <a href="/TrendTrackV2/frontend/admin/users.html" class="admin-nav-link" data-page="users">
        <span class="nav-icon">👥</span> Users
      </a>
    </nav>
    <div class="admin-sidebar-footer">
      <a href="/TrendTrackV2/frontend/index.html"><span class="nav-icon">🏠</span> View Store</a>
      <a href="#" onclick="adminLogout()"><span class="nav-icon">🚪</span> Logout</a>
    </div>
  </aside>`;

  const main = `
  <div class="admin-main" id="adminMain">
    <header class="admin-topbar">
      <div class="admin-topbar-left">
        <button class="admin-sidebar-toggle" id="adminSidebarToggle" onclick="toggleAdminSidebar()">☰</button>
        <div>
          <h1 id="pageTitle">${pageTitle}</h1>
          <p id="pageSubtitle">${pageSubtitle}</p>
        </div>
      </div>
      <div class="admin-topbar-right">
        <a href="/TrendTrackV2/frontend/index.html" class="admin-view-store-btn">
          <span>🏪</span> View Store
        </a>
        <div class="admin-user-pill">
          <div class="admin-user-avatar" id="adminUserAvatar">A</div>
          <span class="admin-user-name" id="adminUserName">Admin</span>
        </div>
      </div>
    </header>
    <div class="admin-content" id="adminContent"></div>
  </div>`;

  document.body.classList.add('admin-page');
  document.body.style.display = 'flex';
  document.body.style.minHeight = '100vh';
  document.body.style.background = 'var(--gray-100)';
  document.body.insertAdjacentHTML('afterbegin', sidebar + main);

  // Highlight active link
  const page = document.body.dataset.page;
  document.querySelectorAll('.admin-nav-link').forEach(a => {
    if (a.dataset.page === page) a.classList.add('active');
  });

  // Mobile overlay close
  document.addEventListener('click', (e) => {
    const sb = document.getElementById('adminSidebar');
    const toggle = document.getElementById('adminSidebarToggle');
    if (window.innerWidth <= 1024 && sb && !sb.contains(e.target) && e.target !== toggle) {
      sb.classList.remove('open');
    }
  });
}

function toggleAdminSidebar() {
  document.getElementById('adminSidebar')?.classList.toggle('open');
}

async function adminLogout() {
  await post(API.logout, {});
  clearSession();
  window.location.href = '/TrendTrackV2/frontend/login.html';
}

// ---- Modal helpers ----
function openModal(backdropId) {
  document.getElementById(backdropId)?.classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeModal(backdropId) {
  document.getElementById(backdropId)?.classList.remove('open');
  document.body.style.overflow = '';
}
function setupModalClose(backdropId) {
  const bd = document.getElementById(backdropId);
  if (!bd) return;
  bd.addEventListener('click', (e) => { if (e.target === bd) closeModal(backdropId); });
}

// ---- Confirm Delete ----
function confirmDelete(message, onConfirm) {
  if (confirm(message)) onConfirm();
}

// ---- Render empty state ----
function emptyState(message = 'No records found.', icon = '📭') {
  return `<tr><td colspan="100" style="text-align:center;padding:56px;color:var(--gray-400)"><div style="font-size:3rem;margin-bottom:12px">${icon}</div><p style="font-size:1rem;font-weight:600">${message}</p></td></tr>`;
}

// ---- Loading row ----
function loadingRow(cols = 5) {
  return `<tr><td colspan="${cols}" style="padding:40px;text-align:center;color:var(--gray-400)"><div style="display:inline-block;width:28px;height:28px;border:3px solid var(--border);border-top-color:var(--black);border-radius:50%;animation:spin .8s linear infinite"></div><p style="margin-top:12px;font-size:0.85rem">Loading…</p></td></tr>`;
}

// Spin animation injected once
const _spinStyle = document.createElement('style');
_spinStyle.textContent = '@keyframes spin{to{transform:rotate(360deg)}}';
document.head.appendChild(_spinStyle);
