/* AidFleet - API-backed helpers (PHP + MySQL) */

(function() {
  if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    var loader = document.createElement('div');
    loader.id = 'globalLoader';
    loader.innerHTML = '<div class="global-spinner"></div>';
    document.documentElement.appendChild(loader);

    window.addEventListener('load', function() {
      setTimeout(function() {
        var el = document.getElementById('globalLoader');
        if (el) {
          el.classList.add('loader-hidden');
          setTimeout(function() { if (el.parentNode) el.parentNode.removeChild(el); }, 400);
        }
      }, 100);
    });
  }
})();

let _aidfleetUserCache = undefined; // undefined=unknown, null=not logged in, object=logged in
let _aidfleetCsrfToken = null;

function aidfleetReadCookie(name) {
  const parts = ('; ' + document.cookie).split('; ' + name + '=');
  if (parts.length === 2) return parts.pop().split(';').shift() || '';
  return '';
}

async function aidfleetGetCsrfToken() {
  if (_aidfleetCsrfToken) return _aidfleetCsrfToken;

  const cookieToken = aidfleetReadCookie('AIDFLEET_CSRF');
  if (cookieToken) {
    _aidfleetCsrfToken = decodeURIComponent(cookieToken);
    return _aidfleetCsrfToken;
  }

  const root = window.APP_ROOT || "";
  const res = await fetch(root + 'api/auth/csrf.php', {
    credentials: 'include',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  const data = await res.json();
  if (!res.ok || !data || !data.csrf_token) {
    throw new Error('Could not initialize security token');
  }
  _aidfleetCsrfToken = data.csrf_token;
  return _aidfleetCsrfToken;
}
window.aidfleetGetCsrfToken = aidfleetGetCsrfToken;

async function apiFetch(path, options = {}) {
  const root = window.APP_ROOT || "";
  if (!path.startsWith(root) && !path.startsWith("http")) path = root + path;

  const method = (options.method || 'GET').toUpperCase();
  const headers = new Headers(options.headers || {});
  headers.set('X-Requested-With', 'XMLHttpRequest');
  if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
    headers.set('X-CSRF-Token', await aidfleetGetCsrfToken());
  }

  const res = await fetch(path, {
    credentials: 'include',
    ...options,
    headers,
  });
  const text = await res.text();
  let data = null;
  try { 
    data = text ? JSON.parse(text) : null; 
  } catch (e) { 
    console.error('API Error:', e, text);
    return { success: false, message: 'Invalid server response', _status: res.status };
  }
  if (!res.ok) {
    const msg = (data && data.message) ? data.message : 'Request failed';
    // Spread all API response fields (e.g. phone_unverified, phone, role) so callers can inspect them
    return Object.assign({ success: false, message: msg, _status: res.status }, data || {});
  }
  return data || { success: true };
}

async function getCurrentUser() {
  const r = await apiFetch('api/auth/me.php');
  _aidfleetUserCache = r.user || null;
  return _aidfleetUserCache;
}

async function loginUser(email, password) {
  const r = await apiFetch('api/auth/login.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password }),
  });
  if (r.success) {
    _aidfleetUserCache = r.user;
    try { sessionStorage.setItem('aidfleet_sidebar_open', window.innerWidth > 1024 ? '1' : '0'); } catch(e) {}
  }
  return r;
}

async function loginAdmin(email, password) {
  const r = await apiFetch('api/auth/admin_login.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password }),
  });
  if (r.success) {
    _aidfleetUserCache = r.user;
    try { sessionStorage.setItem('aidfleet_sidebar_open', window.innerWidth > 1024 ? '1' : '0'); } catch(e) {}
  }
  return r;
}

// driverExtra: { licenseNumber, ambulancePlate, ambulanceType, medicalFile, licenseFile }
async function registerUser(name, email, phone, password, role, driverExtra = {}) {
  if (role === 'driver') {
    const fd = new FormData();
    fd.append('role', 'driver');
    fd.append('full_name', name);
    fd.append('email', email);
    fd.append('phone', phone);
    fd.append('password', password);
    fd.append('license_number', driverExtra.licenseNumber || '');
    fd.append('ambulance_registration', driverExtra.ambulancePlate || '');
    fd.append('ambulance_type', driverExtra.ambulanceType || '');
    if (driverExtra.medicalFile) fd.append('medical_document', driverExtra.medicalFile);
    if (driverExtra.licenseFile) fd.append('license_document', driverExtra.licenseFile);
    const r = await apiFetch('api/auth/register.php', { method: 'POST', body: fd });
    if (r.success) {
      _aidfleetUserCache = r.user;
      try { sessionStorage.setItem('aidfleet_sidebar_open', window.innerWidth > 1024 ? '1' : '0'); } catch(e) {}
    }
    return r;
  }

  const r = await apiFetch('api/auth/register.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ role: 'requester', full_name: name, email, phone, password }),
  });
  if (r.success) {
    _aidfleetUserCache = r.user;
    try { sessionStorage.setItem('aidfleet_sidebar_open', window.innerWidth > 1024 ? '1' : '0'); } catch(e) {}
  }
  return r;
}

function confirmLogout() {
  const existing = document.getElementById('logoutConfirmDialog');
  if (existing) existing.remove();

  const backdrop = document.createElement('div');
  backdrop.id = 'logoutConfirmDialog';
  backdrop.style.position = 'fixed'; backdrop.style.inset = '0';
  backdrop.style.background = 'rgba(15,23,42,0.7)'; backdrop.style.display = 'flex';
  backdrop.style.alignItems = 'center'; backdrop.style.justifyContent = 'center';
  backdrop.style.zIndex = '9999';

  backdrop.innerHTML = '<div class="card card-padded text-center" style="max-width:320px;width:100%;">' +
    '<div style="width:48px;height:48px;border-radius:50%;background:rgba(220,38,38,0.1);color:#dc2626;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;"><span class="material-symbols-outlined" style="font-size:1.2em;">logout</span></div>' +
    '<h3 class="font-bold mb-2">Log Out</h3>' +
    '<p class="text-sm text-muted" style="margin-bottom: 28px;">Are you sure you want to log out of your account?</p>' +
    '<div class="flex-row gap-4">' +
      '<button class="btn" style="flex:1;" onclick="document.getElementById(\'logoutConfirmDialog\').remove()">Cancel</button>' +
      '<button class="btn btn-emergency" id="btnLogoutConfirm" style="flex:1;" onclick="doLogout()">Logout</button>' +
    '</div></div>';

  document.body.appendChild(backdrop);
}

window.doLogout = async function() {
  const btn = document.getElementById('btnLogoutConfirm');
  if (btn) {
    btn.innerHTML = '<span class="btn-spinner" style="display:inline-block;border-color:currentColor;border-bottom-color:transparent;"></span> Logging out...';
    btn.disabled = true;
  }
  await new Promise(r => setTimeout(r, 1500));
  logoutUser();
};

async function logoutUser() {
  const role = _aidfleetUserCache ? _aidfleetUserCache.role : null;
  await apiFetch('api/auth/logout.php', { method: 'POST' });
  _aidfleetUserCache = null;
  if (role === 'admin') {
    window.location.href = (window.APP_ROOT || '') + 'a-login.html';
  } else {
    window.location.href = (window.APP_ROOT || '') + 'auth/login.html';
  }
}

function _isCompactSidebar() {
  return window.innerWidth <= 1024;
}

window._navigateFromSidebar = function(event, href) {
  if (event) event.preventDefault();
  var link = event && event.currentTarget ? event.currentTarget : null;
  if (link) link.classList.add('active');
  if (!_isCompactSidebar()) {
    window.location.href = href;
    return;
  }
  try { sessionStorage.setItem('aidfleet_sidebar_open', '0'); } catch(e) {}
  var sb = document.getElementById('appSidebar');
  if (sb && !sb.classList.contains('collapsed')) {
    sb.classList.add('collapsed');
    document.body.classList.remove('sidebar-expanded');
    document.body.classList.add('sidebar-collapsed');
    _removeBackdrop();
    setTimeout(function() { window.location.href = href; }, 280);
  } else {
    window.location.href = href;
  }
};

window.toggleSidebar = function() {
  var sb = document.getElementById('appSidebar');
  if (!sb) return;

  var isMobileView = _isCompactSidebar();

  if (isMobileView) {
    // Mobile: overlay drawer pattern
    var isOpen = !sb.classList.contains('collapsed');
    if (isOpen) {
      // Close
      sb.classList.add('collapsed');
      document.body.classList.remove('sidebar-expanded');
      document.body.classList.add('sidebar-collapsed');
      _removeBackdrop();
      sessionStorage.setItem('aidfleet_sidebar_open', '0');
    } else {
      // Open
      sb.classList.remove('collapsed');
      document.body.classList.add('sidebar-expanded');
      document.body.classList.remove('sidebar-collapsed');
      _createBackdrop();
      sessionStorage.setItem('aidfleet_sidebar_open', '1');
    }
  } else {
    // Tablet/Desktop: squeeze mode
    sb.classList.toggle('collapsed');
    if (sb.classList.contains('collapsed')) {
      document.body.classList.add('sidebar-collapsed');
      document.body.classList.remove('sidebar-expanded');
      sessionStorage.setItem('aidfleet_sidebar_open', '0');
    } else {
      document.body.classList.add('sidebar-expanded');
      document.body.classList.remove('sidebar-collapsed');
      sessionStorage.setItem('aidfleet_sidebar_open', '1');
    }
  }
};

function _createBackdrop() {
  if (document.getElementById('sidebarBackdrop')) return;
  var bd = document.createElement('div');
  bd.id = 'sidebarBackdrop';
  bd.className = 'sidebar-backdrop';
  bd.addEventListener('click', function() { toggleSidebar(); });
  document.body.appendChild(bd);
  // Trigger transition
  requestAnimationFrame(function() { bd.classList.add('sidebar-backdrop-visible'); });
}

function _removeBackdrop() {
  var bd = document.getElementById('sidebarBackdrop');
  if (!bd) return;
  bd.classList.remove('sidebar-backdrop-visible');
  setTimeout(function() { if (bd.parentNode) bd.parentNode.removeChild(bd); }, 250);
}


async function requireAuth(allowedRoles) {
  const user = await getCurrentUser();
  if (!user) { window.location.href = (window.APP_ROOT || '') + 'auth/login.html'; return null; }
  if (allowedRoles && !allowedRoles.includes(user.role)) { window.location.href = (window.APP_ROOT || '') + 'auth/login.html'; return null; }
  return user;
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function escapeAttr(value) {
  return escapeHtml(value).replace(/`/g, '&#096;');
}

function safeAssetUrl(value) {
  const path = String(value || '').trim();
  if (!path) return '';
  if (/^data:image\/(?:png|jpe?g|webp|gif);base64,/i.test(path)) return path;
  if (/^(?:javascript|vbscript|data):/i.test(path)) return '';
  return (window.APP_ROOT || '') + path.replace(/^\/+/, '');
}

function safeNotificationHtml(value) {
  const icons = [];
  const marked = String(value ?? '').replace(
    /<span\s+class=(["'])(?:material-symbols-outlined(?:\s+notif-icon)?|notif-icon\s+material-symbols-outlined)\1[^>]*>([a-z0-9_]+)<\/span>/gi,
    function(_, quote, iconName) {
      const idx = icons.length;
      icons.push('<span class="material-symbols-outlined notif-icon">' + escapeHtml(iconName) + '</span>');
      return '__AIDFLEET_ICON_' + idx + '__';
    }
  );
  return escapeHtml(marked).replace(/__AIDFLEET_ICON_(\d+)__/g, function(match, idx) {
    return icons[Number(idx)] || '';
  });
}

window.escapeHtml = escapeHtml;
window.escapeAttr = escapeAttr;
window.safeAssetUrl = safeAssetUrl;

function getStatusBadge(status) {
  const labels = {
    pending: 'Pending', driver_selected: 'Driver Selected', accepted: 'Accepted',
    en_route: 'En Route', on_scene: 'On Scene', transporting: 'Navigating',
    completed: 'Completed', cancelled: 'Cancelled', rejected: 'Rejected'
  };
  const str = String(status);
  const badgeClass = str.replace(/[^a-z0-9_-]/gi, '');
  const displayLabel = labels[str] || (str.charAt(0).toUpperCase() + str.slice(1).replace(/_/g, ' '));
  return '<span class="badge badge-' + badgeClass + '">' + escapeHtml(displayLabel) + '</span>';
}
function getPriorityBadge(priority) {
  const str = String(priority);
  return '<span class="badge badge-' + str.replace(/[^a-z0-9_-]/gi, '') + '">' + escapeHtml(str) + '</span>';
}
function getDistance(lat1, lng1, lat2, lng2) {
  const R = 6371;
  const dLat = (lat2 - lat1) * Math.PI / 180;
  const dLng = (lng2 - lng1) * Math.PI / 180;
  const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
    Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
    Math.sin(dLng / 2) * Math.sin(dLng / 2);
  const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  return R * c;
}
function renderSidebar(role, activePath) {
  const user = _aidfleetUserCache && _aidfleetUserCache !== undefined ? _aidfleetUserCache : null;
  const driverVerified = role === 'driver' && user && user.verification_status === 'approved';

  const navItems = {
    requester: [
      { label: 'Dashboard', icon: '<span class="material-symbols-outlined">dashboard</span>', path: (window.APP_ROOT || '') + 'requester/dashboard.html' },
      { label: 'New Emergency', icon: '<span class="material-symbols-outlined">warning</span>', path: (window.APP_ROOT || '') + 'requester/emergency-form.html' },
      { label: 'Navigation', icon: '<span class="material-symbols-outlined">location_on</span>', path: (window.APP_ROOT || '') + 'requester/navigation.html' },
      { label: 'Request History', icon: '<span class="material-symbols-outlined">history</span>', path: (window.APP_ROOT || '') + 'requester/history.html' },
    ],
    driver: [
      { label: 'Dashboard', icon: '<span class="material-symbols-outlined">dashboard</span>', path: (window.APP_ROOT || '') + 'driver/dashboard.html' },
      { label: 'Navigation', icon: '<span class="material-symbols-outlined">location_on</span>', path: (window.APP_ROOT || '') + 'driver/navigation.html' },
      { label: 'Dispatch History', icon: '<span class="material-symbols-outlined">assignment</span>', path: (window.APP_ROOT || '') + 'driver/history.html' },
      {label: 'Compliance', icon: '<span class="material-symbols-outlined">upload_file</span>', path: (window.APP_ROOT || '') + 'driver/reupload.html', highlight: !driverVerified,
},
    ],
    admin: [
      { label: 'Dashboard', icon: '<span class="material-symbols-outlined">dashboard</span>', path: (window.APP_ROOT || '') + 'admin/dashboard.html' },
      { label: 'Drivers', icon: '<span class="material-symbols-outlined">group</span>', path: (window.APP_ROOT || '') + 'admin/verify-drivers.html' },
      { label: 'Dispatches', icon: '<span class="material-symbols-outlined">radio</span>', path: (window.APP_ROOT || '') + 'admin/dispatches.html' },
      { label: 'Users', icon: '<span class="material-symbols-outlined">contacts</span>', path: (window.APP_ROOT || '') + 'admin/users.html' },
      { label: 'Analytics', icon: '<span class="material-symbols-outlined">pie_chart</span>', path: (window.APP_ROOT || '') + 'admin/analytics.html' },
      { label: 'System Logs', icon: '<span class="material-symbols-outlined">description</span>', path: (window.APP_ROOT || '') + 'admin/logs.html' },
    ],
  };

  const roleLabel = role === 'requester' ? 'Emergency Requester' : role === 'driver' ? 'Ambulance Driver' : 'System Administrator';
  const items = navItems[role] || [];
  let resolvedActive = activePath;
  if (!activePath.includes('/')) {
    if (activePath === 'profile.html' || activePath === 'settings.html') {
      resolvedActive = (window.APP_ROOT || '') + 'shared/settings.html';
    } else if (activePath === 'tracking.html') {
      resolvedActive = (window.APP_ROOT || '') + role + '/navigation.html';
    } else {
      resolvedActive = (window.APP_ROOT || '') + role + '/' + activePath;
    }
  }

  const isCompact = _isCompactSidebar();

  let nav = items.map(item => {
    const isActive = item.path === resolvedActive;
    const extraClass = item.highlight ? ' nav-highlight' : '';
    const clickHandler = isCompact ? ' onclick="_navigateFromSidebar(event, \'' + item.path.replace(/'/g, "\\'") + '\')"' : '';
    return '<a href="' + item.path + '" class="' + (isActive ? 'active' : '') + extraClass + '"' + clickHandler + '>' + item.icon + ' <span>' + item.label + '</span></a>';
  }).join('');
  const profileActive = resolvedActive === (window.APP_ROOT || '') + 'shared/settings.html';
  const settingsPath = (window.APP_ROOT || '') + 'shared/settings.html';
  const profileClick = isCompact ? ' onclick="_navigateFromSidebar(event, \'' + settingsPath.replace(/'/g, "\\'") + '\')"' : '';
  const profileLink = '<a href="' + settingsPath + '" class="' + (profileActive ? 'active' : '') + '"' + profileClick + '><span class="material-symbols-outlined">settings</span> <span>Settings</span></a>';

  // Restore sidebar state from sessionStorage so it persists across page navigations
  let savedOpen = null;
  try { savedOpen = sessionStorage.getItem('aidfleet_sidebar_open'); } catch(e) {}
  let sidebarOpen;
  if (savedOpen === '1') {
    sidebarOpen = true;
  } else if (savedOpen === '0') {
    sidebarOpen = false;
  } else {
    sidebarOpen = window.innerWidth > 1024;
  }
  let sidebarClass = sidebarOpen ? 'sidebar' : 'sidebar collapsed';
  // Sync body classes
  if (typeof document !== 'undefined') {
    if (sidebarOpen) {
      document.body.classList.add('sidebar-expanded');
      document.body.classList.remove('sidebar-collapsed');
    } else {
      document.body.classList.add('sidebar-collapsed');
      document.body.classList.remove('sidebar-expanded');
    }
    // On mobile, recreate backdrop if sidebar was left open
    if (isCompact && sidebarOpen) {
      requestAnimationFrame(function() { _createBackdrop(); });
    }
  }
  let avatarContent = user && user.profile_image
    ? '<img src="' + escapeAttr(safeAssetUrl(user.profile_image)) + '" style="width:100%;height:100%;border-radius:50%;object-fit:cover;" alt="Avatar">'
    : (user ? escapeHtml(String(user.name || '?').charAt(0)) : '?');
  let ratingContent = '';
  if (user && user.avg_rating !== undefined) {
    let ratingVal = Number(user.avg_rating) || 5;
    let starsHtml = '';
    for(let i=1; i<=5; i++) {
      starsHtml += (i <= Math.round(ratingVal)) ? '<span style="color:#fbbf24;">★</span>' : '<span style="color:#cbd5e1;">★</span>';
    }
    ratingContent = '<div style="font-size:12px;margin-top:2px;">' + starsHtml + ' <span style="color:var(--muted-foreground);margin-left:4px;">' + ratingVal.toFixed(1) + '</span></div>';
  }

  return '<aside class="' + sidebarClass + '" id="appSidebar">' +
    '<div class="sidebar-header-action"><button type="button" class="sidebar-close-btn" onclick="toggleSidebar()"><span class="material-symbols-outlined" class="icon-close">close</span></button></div>' +
    '<nav class="sidebar-nav">' + nav + '</nav>' +
    '<nav class="sidebar-nav-bottom">' + profileLink + '</nav>' +
    '<div class="sidebar-user"><div class="sidebar-avatar">' + avatarContent + '</div>' +
    '<div class="sidebar-user-info"><p>' + (user ? escapeHtml(user.name) : '') + '</p><span>' + escapeHtml(roleLabel) + '</span>' + ratingContent + '</div>' +
    '</div></aside>';
}
// --- Notification State with sessionStorage persistence ---
// Load previously-read notification keys from sessionStorage so read state
// survives page navigations within the same browser session.
let _aidfleetNotificationState = {
  hasUnread: false,
  readKeys: (function() {
    try {
      var saved = sessionStorage.getItem('aidfleet_readNotifKeys');
      return saved ? new Set(JSON.parse(saved)) : new Set();
    } catch(e) { return new Set(); }
  })(),
  dropdownOpen: false,
  lastNotifications: []
};

function _notifKey(n) {
  return (n.message || '').replace(/<[^>]*>/g, '').trim();
}

// Persist readKeys to sessionStorage
function _saveReadKeys() {
  try {
    sessionStorage.setItem('aidfleet_readNotifKeys',
      JSON.stringify(Array.from(_aidfleetNotificationState.readKeys)));
  } catch(e) { /* ignore quota errors */ }
}



function renderTopbar(newNotifications = []) {
  // Load all persistent notifications
  let allNotifs = [];
  let deletedKeys = new Set();
  try {
    const saved = localStorage.getItem('aidfleet_all_notifications');
    if (saved) allNotifs = JSON.parse(saved);
    const delSaved = localStorage.getItem('aidfleet_deleted_notifications');
    if (delSaved) deletedKeys = new Set(JSON.parse(delSaved));
  } catch(e) {}

  // Merge incoming notifications, avoiding duplicates by message content only
  // (do NOT compare timestamps — polling rebuilds timestamps each call, causing
  //  the same semantic notification to appear as new every poll cycle)
  newNotifications.forEach(n => {
    const key = _notifKey(n);
    if (deletedKeys.has(key)) return;
    const exists = allNotifs.some(existing => _notifKey(existing) === key);
    if (!exists) {
      allNotifs.unshift(n);
    }
  });

  // Cap to 50 notifications
  if (allNotifs.length > 50) allNotifs = allNotifs.slice(0, 50);

  try {
    localStorage.setItem('aidfleet_all_notifications', JSON.stringify(allNotifs));
  } catch(e) {}

  const notifications = allNotifs;
  _aidfleetNotificationState.lastNotifications = notifications;

  // Determine unread: any notification whose key is not in readKeys
  var hasUnread = false;
  notifications.forEach(function(n) {
    if (!_aidfleetNotificationState.readKeys.has(_notifKey(n))) {
      hasUnread = true;
    }
  });
  _aidfleetNotificationState.hasUnread = hasUnread;

  var dotClass = hasUnread ? ' topbar-bell-unread' : '';
  var dropdownDisplay = _aidfleetNotificationState.dropdownOpen ? 'block' : 'none';

  return '<header class="topbar">' +
    '<div class="topbar-logo-area">' +
      '<button type="button" class="topbar-menu-btn" onclick="toggleSidebar()"><span class="material-symbols-outlined" class="icon-hamburger">menu</span></button>' +
      '<img src="' + (window.APP_ROOT || '') + 'images/aidfleet-logo.png" alt="AidFleet Logo" class="topbar-logo-img">' +
      '<div class="topbar-logo-title">AidFleet</div>' +
    '</div>' +
    '<div class="topbar-spacer"></div>' +
    '<div class="notification-container">' +
      '<button class="topbar-bell' + dotClass + '" onclick="toggleNotifications()"><span class="material-symbols-outlined">notifications</span></button>' +
      '<div id="notificationDropdown" class="notification-dropdown" style="display:' + dropdownDisplay + ';">' +
        '<div class="notification-header" style="display:flex; justify-content:space-between; align-items:center;">' +
          '<strong>Notifications</strong>' +
          '<button onclick="clearNotifications()" style="background:none;border:none;cursor:pointer;color:var(--muted-foreground);" title="Clear all notifications"><span class="material-symbols-outlined" style="font-size:1.2em;">delete</span></button>' +
        '</div>' +
        '<div class="notification-list">' +
          (notifications.length > 0
            ? notifications.map(function(n) {
                var isRead = _aidfleetNotificationState.readKeys.has(_notifKey(n));
                var itemClass = 'notification-item' + (isRead ? '' : ' notification-item-unread');
                var timeStr = '';
                if (n.time) {
                  var d = new Date(n.time);
                  if (!isNaN(d.getTime())) {
                    var now = new Date();
                    var diffMs = now - d;
                    var diffMin = Math.floor(diffMs / 60000);
                    if (diffMin < 1) timeStr = 'Just now';
                    else if (diffMin < 60) timeStr = diffMin + 'm ago';
                    else if (diffMin < 120) timeStr = Math.floor(diffMin / 60) + 'h ago';
                    else timeStr = d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true }).toLowerCase() + ' ' + d.toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
                  }
                }
                return '<div class="' + itemClass + '">' +
                  '<div class="notification-item-msg">' + safeNotificationHtml(n.message) + '</div>' +
                  (timeStr ? '<div class="notification-item-time">' + timeStr + '</div>' : '') +
                '</div>';
              }).join('')
            : '<div class="notification-item text-muted">No notifications</div>') +
        '</div>' +
      '</div>' +
    '</div>' +
    '<button class="topbar-logout" onclick="confirmLogout()" title="Logout"><span class="material-symbols-outlined" style="font-size:1.2em;">logout</span> <span style="display:inline;">Log Out</span></button>' +
    '</header>';
}

window.toggleNotifications = function() {
  var wasOpen = _aidfleetNotificationState.dropdownOpen;
  _aidfleetNotificationState.dropdownOpen = !wasOpen;
  var dropdown = document.getElementById('notificationDropdown');
  if (dropdown) {
    dropdown.style.display = _aidfleetNotificationState.dropdownOpen ? 'block' : 'none';
  }
  // On CLOSE: mark all current notifications as read and update visuals
  if (wasOpen) {
    _markAllNotificationsRead();
  }
};

window.clearNotifications = function() {
  try {
    const all = JSON.parse(localStorage.getItem('aidfleet_all_notifications') || '[]');
    let delSaved = new Set(JSON.parse(localStorage.getItem('aidfleet_deleted_notifications') || '[]'));
    all.forEach(n => delSaved.add(_notifKey(n)));
    localStorage.setItem('aidfleet_deleted_notifications', JSON.stringify(Array.from(delSaved)));

    localStorage.removeItem('aidfleet_all_notifications');
    _aidfleetNotificationState.readKeys.clear();
    _saveReadKeys();
  } catch(e) {}
  var dropdown = document.getElementById('notificationDropdown');
  if (dropdown) {
     var list = dropdown.querySelector('.notification-list');
     if (list) list.innerHTML = '<div class="notification-item text-muted">No notifications</div>';
  }
  var bell = document.querySelector('.topbar-bell');
  if (bell) bell.classList.remove('topbar-bell-unread');
};

function _markAllNotificationsRead() {
  var changed = false;
  // Add all current notification keys to readKeys so they are treated as read
  _aidfleetNotificationState.lastNotifications.forEach(function(n) {
    var key = _notifKey(n);
    if (!_aidfleetNotificationState.readKeys.has(key)) {
      _aidfleetNotificationState.readKeys.add(key);
      changed = true;
    }
  });

  if (changed) {
    _aidfleetNotificationState.hasUnread = false;

    // Persist to sessionStorage so read state survives page navigations
    _saveReadKeys();

    // Remove the red dot from the bell icon
    var bellBtn = document.querySelector('.topbar-bell');
    if (bellBtn) bellBtn.classList.remove('topbar-bell-unread');

    // Remove all unread highlight styling from notification items in the DOM
    var items = document.querySelectorAll('.notification-item-unread');
    items.forEach(function(el) { el.classList.remove('notification-item-unread'); });
  }
}

// Close dropdown when clicking anywhere outside the notification container
document.addEventListener('click', function(e) {
  if (!e.target.closest('.notification-container')) {
    if (_aidfleetNotificationState.dropdownOpen) {
      _aidfleetNotificationState.dropdownOpen = false;
      var dropdown = document.getElementById('notificationDropdown');
      if (dropdown) dropdown.style.display = 'none';
      // Mark all as read on close
      _markAllNotificationsRead();
    }
  }
});

// Helper for inline form errors with shake animation
function showInlineError(elementId, message) {
  var el = document.getElementById(elementId);
  if (!el) return;
  el.textContent = message;
  el.style.display = 'block';
  el.classList.remove('shake');
  void el.offsetWidth; // force reflow
  el.classList.add('shake');
}

function updateInlineError(elementId, message) {
  var el = document.getElementById(elementId);
  if (!el) return;
  el.textContent = message;
  el.style.display = 'block';
}

function showInlineSuccess(elementId, message) {
  var el = document.getElementById(elementId);
  if (!el) return;
  el.textContent = message;
  el.style.display = 'block';
}

// Global Toast Notification System
// Status can be 'success', 'error', 'info', 'warning'
window.showToast = function(title, message, status = 'info') {
  let container = document.getElementById('toastContainer');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toastContainer';
    document.body.appendChild(container);
  }
  
  const toast = document.createElement('div');
  toast.className = 'toast-notification toast-' + status;
  toast.innerHTML = 
    '<div class="toast-content">' +
      '<h4>' + escapeHtml(title) + '</h4>' +
      '<p>' + escapeHtml(message) + '</p>' +
    '</div>' +
    '<div class="toast-progress"></div>';
    
  container.appendChild(toast);
  
  // The progress bar shrinks over 6s. Then we fade out and remove.
  setTimeout(function() {
    toast.style.opacity = '0';
    toast.style.transition = 'opacity 0.3s';
    setTimeout(function() {
      if (toast.parentNode) toast.parentNode.removeChild(toast);
    }, 300);
  }, 6000);
};

/**
 * Show the OTP verification modal.
 * @param {string} phone    - Full phone number (E.164)
 * @param {string} role     - 'requester' or 'driver'
 * @param {function} onSuccess - Callback when verification succeeds
 */
window.showOtpModal = function(phone, role, onSuccess, source) {
  var existing = document.getElementById('otpVerifyModal');
  if (existing) existing.remove();
  clearInterval(_otpLockTimerInterval);

  // Role-based accent colors
  var isDriver = (role === 'driver');
  var accent = isDriver ? '#3b82f6' : '#dc2626';
  var accentLight = isDriver ? 'rgba(59,130,246,0.12)' : 'rgba(220,38,38,0.12)';
  var gradientBtn = isDriver
    ? 'linear-gradient(135deg,#3b82f6,#2563eb)'
    : 'linear-gradient(135deg,#dc2626,#b91c1c)';
  var focusColor = isDriver ? '#60a5fa' : '#f87171';

  var maskedPhone = _maskOtpPhone(phone);

  var backdrop = document.createElement('div');
  backdrop.id = 'otpVerifyModal';
  backdrop.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.85);display:flex;align-items:center;justify-content:center;z-index:10000;backdrop-filter:blur(4px);animation:fadeIn 0.2s ease;';

  // Build OTP input fields
  var inputsHtml = '';
  for (var i = 0; i < 6; i++) {
    inputsHtml += '<input type="text" maxlength="1" class="otp-digit-input" data-idx="' + i + '" inputmode="numeric"' + (i === 0 ? ' autocomplete="one-time-code"' : '') + ' style="width:44px;height:52px;text-align:center;font-size:22px;font-weight:700;border-radius:10px;border:2px solid rgba(255,255,255,0.15);background:rgba(15,23,42,0.6);color:#f1f5f9;outline:none;transition:border-color 0.2s,box-shadow 0.2s;">';
  }

  backdrop.innerHTML =
    '<div style="background:rgba(30,41,59,0.95);border:1px solid rgba(255,255,255,0.1);border-radius:16px;padding:32px;max-width:420px;width:90%;box-shadow:0 24px 48px rgba(0,0,0,0.4);position:relative;">' +
      // Back/close button (top-left)
      '<button id="btnOtpBack" type="button" title="Go back" style="position:absolute;top:16px;left:16px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);border-radius:8px;width:36px;height:36px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#94a3b8;transition:all 0.2s;" onmouseover="this.style.background=\'rgba(255,255,255,0.12)\';this.style.color=\'#f1f5f9\'" onmouseout="this.style.background=\'rgba(255,255,255,0.06)\';this.style.color=\'#94a3b8\'">' +
        '<span class="material-symbols-outlined" style="font-size:1.2em;">arrow_back</span>' +
      '</button>' +
      '<div id="otpEntryPanel">' +
        '<div style="text-align:center;margin-bottom:24px;padding-top:8px;">' +
          '<div style="width:56px;height:56px;border-radius:50%;background:' + accentLight + ';color:' + accent + ';display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">' +
            '<span class="material-symbols-outlined" style="font-size:1.2em;">smartphone</span>' +
          '</div>' +
          '<h3 style="color:#f1f5f9;font-size:20px;font-weight:700;margin:0 0 8px;">Verify Your Phone</h3>' +
          '<p style="color:#94a3b8;font-size:14px;margin:0;">Enter the 6-digit code sent to</p>' +
          '<div style="display:flex;align-items:center;justify-content:center;gap:8px;margin:4px 0 0;">' +
            '<p id="otpMaskedPhone" style="color:#e2e8f0;font-size:15px;font-weight:600;margin:0;">' + maskedPhone + '</p>' +
            '<button id="btnChangeOtpNumber" type="button" style="background:none;border:none;color:' + accent + ';font-size:12px;font-weight:600;cursor:pointer;padding:2px 6px;border-radius:4px;transition:background 0.2s;" onmouseover="this.style.background=\'rgba(255,255,255,0.1)\'" onmouseout="this.style.background=\'none\'">Change Number</button>' +
          '</div>' +
        '</div>' +
        '<div id="otpInputContainer" style="display:flex;gap:8px;justify-content:center;margin-bottom:20px;">' + inputsHtml + '</div>' +
        '<div id="otpError" style="display:none;text-align:center;color:#ef4444;font-size:13px;margin-bottom:12px;"></div>' +
        '<button id="btnVerifyOtp" type="button" class="otp-verify-btn" style="background:' + gradientBtn + ';" onclick="handleOtpVerify()">' +
          '<span class="material-symbols-outlined">verified_user</span><span>Verify Phone Number</span>' +
        '</button>' +
        '<div style="text-align:center;margin-top:16px;">' +
          '<p id="otpCountdown" style="color:#64748b;font-size:13px;margin:0;display:flex;align-items:center;justify-content:center;gap:4px;"><span class="material-symbols-outlined" style="font-size:1.2em;">schedule</span> Resend code in <span id="otpTimer">60</span>s</p>' +
          '<button id="btnResendOtp" type="button" style="display:none;background:none;border:none;color:' + accent + ';font-size:13px;cursor:pointer;margin-top:4px;padding:4px 8px;border-radius:6px;transition:background 0.2s;" onmouseover="this.style.background=\'rgba(255,255,255,0.06)\'" onmouseout="this.style.background=\'none\'" onclick="handleOtpResend()">' +
            '<span class="material-symbols-outlined inline-icon">refresh</span> Resend Verification Code' +
          '</button>' +
        '</div>' +
        '<p style="text-align:center;margin-top:16px;font-size:12px;color:#475569;display:flex;align-items:center;justify-content:center;gap:4px;"><span class="material-symbols-outlined" style="font-size:1.2em;">timer</span> Code expires in 5 minutes</p>' +
      '</div>' +
      '<div id="otpChangePanel" style="display:none;padding-top:8px;">' +
        '<div style="text-align:center;margin-bottom:24px;">' +
          '<div style="width:56px;height:56px;border-radius:50%;background:' + accentLight + ';color:' + accent + ';display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">' +
            '<span class="material-symbols-outlined" style="font-size:1.2em;">edit</span>' +
          '</div>' +
          '<h3 style="color:#f1f5f9;font-size:20px;font-weight:700;margin:0 0 8px;">Change Phone Number</h3>' +
          '<p style="color:#94a3b8;font-size:14px;margin:0;">Enter a different Kenyan phone number. It will be saved only after verification.</p>' +
        '</div>' +
        '<label for="otpNewPhone" style="display:block;color:#cbd5e1;font-size:13px;font-weight:600;margin-bottom:8px;">New Phone Number</label>' +
        '<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">' +
          '<span style="padding:10px 12px;border:1px solid rgba(255,255,255,0.12);border-radius:8px;background:rgba(15,23,42,0.6);color:#94a3b8;font-size:13px;">+254</span>' +
          '<input id="otpNewPhone" type="tel" inputmode="numeric" maxlength="9" placeholder="7XX XXX XXX" style="flex:1;width:100%;padding:10px 12px;border:1px solid rgba(255,255,255,0.12);border-radius:8px;background:rgba(15,23,42,0.6);color:#f1f5f9;font-size:14px;outline:none;">' +
        '</div>' +
        '<div id="otpChangeError" style="display:none;text-align:center;color:#ef4444;font-size:13px;margin-bottom:12px;"></div>' +
        '<button id="btnSendChangedOtp" type="button" class="otp-verify-btn" style="background:' + gradientBtn + ';">' +
          '<span class="material-symbols-outlined">send</span><span>Send Code</span>' +
        '</button>' +
        '<button id="btnCancelChangeNumber" type="button" style="width:100%;margin-top:10px;padding:10px 16px;border-radius:8px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.12);color:#e2e8f0;font-size:14px;font-weight:600;cursor:pointer;">' +
          'Cancel' +
        '</button>' +
      '</div>' +
    '</div>';

  document.body.appendChild(backdrop);
// Store state on the modal element
  backdrop._otpPhone = phone;
  backdrop._otpOriginalPhone = phone;
  backdrop._otpPendingPhone = '';
  backdrop._otpRole = role;
  backdrop._otpOnSuccess = onSuccess;
  backdrop._otpSource = source || 'login';
  backdrop._otpAccent = accent;
  backdrop._otpFocusColor = focusColor;
  backdrop._otpGradientBtn = gradientBtn;

  // Back button handler — closes modal and goes back
  document.getElementById('btnOtpBack').addEventListener('click', function() {
    clearInterval(_otpTimerInterval);
    clearInterval(_otpLockTimerInterval);
    backdrop.remove();
    showToast('Verification Cancelled', 'You can verify your phone when you log in.', 'info');
  });
  document.getElementById('btnChangeOtpNumber').addEventListener('click', window.handleChangeNumber);
  document.getElementById('btnCancelChangeNumber').addEventListener('click', window.handleOtpChangeCancel);
  document.getElementById('btnSendChangedOtp').addEventListener('click', window.handleOtpChangedNumberSend);
  var changePhoneInput = document.getElementById('otpNewPhone');
  if (changePhoneInput) {
    changePhoneInput.addEventListener('input', function() {
      this.value = this.value.replace(/\D/g, '').slice(0, 9);
    });
  }

  // Focus first input
  var inputs = backdrop.querySelectorAll('.otp-digit-input');
  if (inputs[0]) inputs[0].focus();

  // Wire up auto-advance, backspace, and paste handling
  inputs.forEach(function(inp, idx) {
    inp.addEventListener('input', function() {
      this.value = this.value.replace(/\D/g, '').slice(0, 1);
      if (this.value && idx < 5) inputs[idx + 1].focus();
      var code = Array.from(inputs).map(function(i) { return i.value; }).join('');
      if (code.length === 6) {
        var verifyBtn = document.getElementById('btnVerifyOtp');
        if (!verifyBtn || !verifyBtn.disabled) {
          setTimeout(function() { handleOtpVerify(); }, 150);
        }
      }
    });
    inp.addEventListener('keydown', function(e) {
      if (e.key === 'Backspace' && !this.value && idx > 0) {
        inputs[idx - 1].focus();
        inputs[idx - 1].value = '';
      }
    });
    inp.addEventListener('focus', function() { this.style.borderColor = focusColor; this.style.boxShadow = '0 0 0 3px ' + focusColor + '33'; });
    inp.addEventListener('blur', function() { this.style.borderColor = 'rgba(255,255,255,0.15)'; this.style.boxShadow = 'none'; });
  });

  // Handle paste of full code
  inputs[0].addEventListener('paste', function(e) {
    e.preventDefault();
    var pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
    for (var j = 0; j < 6; j++) {
      inputs[j].value = pasted[j] || '';
    }
    if (pasted.length === 6) {
      inputs[5].focus();
      var verifyBtnPaste = document.getElementById('btnVerifyOtp');
      if (!verifyBtnPaste || !verifyBtnPaste.disabled) {
        setTimeout(function() { handleOtpVerify(); }, 150);
      }
    }
  });

  _startOtpCountdown(60);
};

// Countdown timer for resend button
var _otpTimerInterval = null;
var _otpLockTimerInterval = null;

function _maskOtpPhone(phone) {
  phone = String(phone || '');
  if (phone.length >= 6) {
    return phone.slice(0, 7) + '\u2022\u2022\u2022' + phone.slice(-3);
  }
  return phone;
}

function _normalizeOtpPhoneInput(value) {
  var digits = String(value || '').replace(/\D/g, '');
  if (digits.indexOf('254') === 0) digits = digits.slice(3);
  if (digits.charAt(0) === '0') digits = digits.slice(1);
  digits = digits.slice(0, 9);
  if (digits.length !== 9) return '';
  return '+254' + digits;
}

function _clearOtpInputs(modal) {
  if (!modal) return;
  var inputs = modal.querySelectorAll('.otp-digit-input');
  inputs.forEach(function(inp) { inp.value = ''; });
  if (inputs[0]) inputs[0].focus();
}

function _showOtpEntryPanel() {
  var entry = document.getElementById('otpEntryPanel');
  var change = document.getElementById('otpChangePanel');
  if (entry) entry.style.display = 'block';
  if (change) change.style.display = 'none';
}

function _showOtpChangePanel() {
  var entry = document.getElementById('otpEntryPanel');
  var change = document.getElementById('otpChangePanel');
  var input = document.getElementById('otpNewPhone');
  var errEl = document.getElementById('otpChangeError');
  if (entry) entry.style.display = 'none';
  if (change) change.style.display = 'block';
  if (errEl) errEl.style.display = 'none';
  if (input) {
    input.value = '';
    setTimeout(function() { input.focus(); }, 50);
  }
}

function _otpLockMessage(secondsLeft) {
  var seconds = Math.max(0, parseInt(secondsLeft, 10) || 0);
  return 'Too many failed attempts. Please try again after ' + seconds + ' second' + (seconds === 1 ? '' : 's') + ' or request a new code.';
}

function _startOtpLockCountdown(lockedUntil, errEl, btn) {
  clearInterval(_otpLockTimerInterval);
  var unlockAt = parseInt(lockedUntil, 10);
  if (!unlockAt) return;
  unlockAt *= 1000;

  function tick() {
    var secondsLeft = Math.max(0, Math.ceil((unlockAt - Date.now()) / 1000));
    if (errEl) {
      errEl.textContent = _otpLockMessage(secondsLeft);
      errEl.style.display = 'block';
    }
    if (secondsLeft <= 0) {
      clearInterval(_otpLockTimerInterval);
      if (btn) {
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.innerHTML = '<span class="material-symbols-outlined">verified_user</span><span>Verify Phone Number</span>';
      }
      if (errEl) errEl.style.display = 'none';
    }
  }

  tick();
  _otpLockTimerInterval = setInterval(tick, 1000);
}

function _startOtpCountdown(seconds) {
  clearInterval(_otpTimerInterval);
  var remaining = seconds;
  var timerEl = document.getElementById('otpTimer');
  var countdownEl = document.getElementById('otpCountdown');
  var resendBtn = document.getElementById('btnResendOtp');
  if (countdownEl) countdownEl.style.display = 'flex';
  if (resendBtn) resendBtn.style.display = 'none';
  if (timerEl) timerEl.textContent = remaining;

  _otpTimerInterval = setInterval(function() {
    remaining--;
    if (timerEl) timerEl.textContent = remaining;
    if (remaining <= 0) {
      clearInterval(_otpTimerInterval);
      if (countdownEl) countdownEl.style.display = 'none';
      if (resendBtn) resendBtn.style.display = 'inline-flex';
}
  }, 1000);
}

// Handle OTP verification submit
window.handleOtpVerify = async function() {
  var modal = document.getElementById('otpVerifyModal');
  if (!modal) return;
  var inputs = modal.querySelectorAll('.otp-digit-input');
  var code = Array.from(inputs).map(function(i) { return i.value; }).join('');
  var errEl = document.getElementById('otpError');
  var btn = document.getElementById('btnVerifyOtp');

  if (btn && btn.disabled) return;

  if (code.length !== 6 || !/^\d{6}$/.test(code)) {
    if (errEl) { errEl.textContent = 'Please enter all 6 digits'; errEl.style.display = 'block'; }
    showToast('Invalid Code', 'Please enter all 6 digits of your verification code.', 'warning');
    return;
  }

  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="btn-spinner" style="display:inline-block;border-color:currentColor;border-bottom-color:transparent;"></span> Verifying...';
    btn.style.opacity = '0.7';
  }
  if (errEl) errEl.style.display = 'none';

  var verifyPayload = { phone: modal._otpPhone, otp: code, role: modal._otpRole };
  if (modal._otpPendingPhone && modal._otpOriginalPhone && modal._otpPendingPhone !== modal._otpOriginalPhone) {
    verifyPayload.account_phone = modal._otpOriginalPhone;
  }

  var result = await apiFetch('api/auth/verify_phone_otp.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(verifyPayload),
  });

  if (result.success) {
    if (btn) {
      btn.innerHTML = '<span class="material-symbols-outlined">check_circle</span><span>Verified!</span>';
      btn.style.background = 'linear-gradient(135deg,#22c55e,#16a34a)';
}
    clearInterval(_otpTimerInterval);
    clearInterval(_otpLockTimerInterval);
    showToast('Phone Verified', 'Your phone number has been verified successfully.', 'success');
    
    // Professional delay before closing (smooth redirection)
    setTimeout(function() {
      if (btn) btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:1.2em;">check_circle</span> Redirecting to Login...';
    }, 1200);

    setTimeout(function() {
      modal.remove();
      if (modal._otpOnSuccess) modal._otpOnSuccess();
    }, 4500); // Wait 4.5 seconds before navigating
  } else {
    if (result.locked) {
      if (btn) {
        btn.disabled = true;
        btn.style.opacity = '0.5';
        btn.innerHTML = '<span class="btn-spinner" style="display:inline-block;width:12px;height:12px;border-color:currentColor;border-bottom-color:transparent;"></span> Refreshing...';
      }
      if (result.locked_until) _startOtpLockCountdown(result.locked_until, errEl, btn);
    } else if (btn) {
      clearInterval(_otpLockTimerInterval);
      btn.disabled = false;
      btn.innerHTML = '<span class="material-symbols-outlined">verified_user</span><span>Verify Phone Number</span>';
      btn.style.opacity = '1';
    }
    if (errEl) {
      if (!result.locked || !result.locked_until) {
        errEl.textContent = result.message || 'Verification failed';
      }
      errEl.style.display = 'block';
    }
    showToast('Verification Failed', (result.locked && result.locked_until) ? _otpLockMessage(Math.max(1, Math.ceil((result.locked_until * 1000 - Date.now()) / 1000))) : (result.message || 'The code you entered is incorrect.'), 'error');
    inputs.forEach(function(inp) { inp.value = ''; });
    if (inputs[0]) inputs[0].focus();
  }
};

// Handle OTP resend
window.handleOtpResend = async function() {
  var modal = document.getElementById('otpVerifyModal');
  if (!modal) return;
  var resendBtn = document.getElementById('btnResendOtp');
  var errEl = document.getElementById('otpError');

  if (resendBtn) {
    resendBtn.disabled = true;
    resendBtn.innerHTML = '<span class="btn-spinner" style="display:inline-block;width:12px;height:12px;border-color:currentColor;border-bottom-color:transparent;"></span> Sending...';
  }

  var resendPayload = { phone: modal._otpPhone };
  if (modal._otpPendingPhone && modal._otpOriginalPhone && modal._otpPendingPhone !== modal._otpOriginalPhone) {
    resendPayload.account_phone = modal._otpOriginalPhone;
    resendPayload.role = modal._otpRole;
  }

  var result = await apiFetch('api/auth/resend_phone_otp.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(resendPayload),
  });

  if (result.success) {
    clearInterval(_otpLockTimerInterval);
    var verifyBtnResend = document.getElementById('btnVerifyOtp');
    if (verifyBtnResend) {
      verifyBtnResend.disabled = false;
      verifyBtnResend.style.opacity = '1';
      verifyBtnResend.innerHTML = '<span class="material-symbols-outlined">verified_user</span><span>Verify Phone Number</span>';
    }
    if (errEl) errEl.style.display = 'none';
    showToast('Code Sent', 'A new verification code has been sent to your phone.', 'success');
    _startOtpCountdown(60);
  } else {
    showToast('Resend Failed', result.message || 'Could not resend the code. Please try again.', 'error');
    if (errEl) { errEl.textContent = result.message || 'Failed to resend'; errEl.style.color = '#ef4444'; errEl.style.display = 'block'; }
    if (result.rate_limited && result.retry_after_seconds) {
      _startOtpCountdown(Math.max(1, parseInt(result.retry_after_seconds, 10) || 180));
    }
  }

  if (resendBtn) {
    resendBtn.disabled = false;
    resendBtn.innerHTML = '<span class="material-symbols-outlined" style="font-size:14px;vertical-align:middle;">refresh</span> Resend Verification Code';
  }
};

window.handleChangeNumber = function() {
  var modal = document.getElementById('otpVerifyModal');
  if (!modal) return;
  _showOtpChangePanel();
};

window.handleOtpChangeCancel = function() {
  _showOtpEntryPanel();
  var modal = document.getElementById('otpVerifyModal');
  if (modal) _clearOtpInputs(modal);
};

window.handleOtpChangedNumberSend = async function() {
  var modal = document.getElementById('otpVerifyModal');
  if (!modal) return;
  var input = document.getElementById('otpNewPhone');
  var errEl = document.getElementById('otpChangeError');
  var btn = document.getElementById('btnSendChangedOtp');
  var newPhone = _normalizeOtpPhoneInput(input ? input.value : '');
  var currentPhone = modal._otpOriginalPhone || modal._otpPhone;

  if (errEl) errEl.style.display = 'none';
  if (!newPhone) {
    if (errEl) { errEl.textContent = 'Enter a valid 9-digit Kenyan phone number.'; errEl.style.display = 'block'; }
    return;
  }
  if (newPhone === currentPhone) {
    if (errEl) { errEl.textContent = 'Enter a different phone number from the current one.'; errEl.style.display = 'block'; }
    return;
  }

  if (btn) {
    btn.disabled = true;
    btn.style.opacity = '0.7';
    btn.innerHTML = '<span class="btn-spinner" style="display:inline-block;border-color:currentColor;border-bottom-color:transparent;"></span> Sending...';
  }

  var phoneCheck = await apiFetch('api/auth/verify_phone.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ phone: newPhone }),
  });

  if (!phoneCheck.success || phoneCheck.registered) {
    if (errEl) {
      errEl.textContent = phoneCheck.reason || 'This phone number is already registered.';
      errEl.style.display = 'block';
    }
    if (btn) {
      btn.disabled = false;
      btn.style.opacity = '1';
      btn.innerHTML = '<span class="material-symbols-outlined">send</span><span>Send Code</span>';
    }
    return;
  }

  var sendResult = await apiFetch('api/auth/resend_phone_otp.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ phone: newPhone, account_phone: currentPhone, role: modal._otpRole }),
  });

  if (!sendResult.success) {
    if (errEl) {
      errEl.textContent = sendResult.message || 'Could not send a code to this number.';
      errEl.style.display = 'block';
    }
    if (btn) {
      btn.disabled = false;
      btn.style.opacity = '1';
      btn.innerHTML = '<span class="material-symbols-outlined">send</span><span>Send Code</span>';
    }
    return;
  }

  modal._otpPhone = newPhone;
  modal._otpPendingPhone = newPhone;
  var maskedEl = document.getElementById('otpMaskedPhone');
  if (maskedEl) maskedEl.textContent = _maskOtpPhone(newPhone);
  _showOtpEntryPanel();
  _clearOtpInputs(modal);
  clearInterval(_otpLockTimerInterval);
  var otpErr = document.getElementById('otpError');
  if (otpErr) otpErr.style.display = 'none';
  _startOtpCountdown(60);
  showToast('Code Sent', 'A verification code has been sent to the new phone number.', 'success');

  if (btn) {
    btn.disabled = false;
    btn.style.opacity = '1';
    btn.innerHTML = '<span class="material-symbols-outlined">send</span><span>Send Code</span>';
  }
};
