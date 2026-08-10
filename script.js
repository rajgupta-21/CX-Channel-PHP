const API = (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1')
  ? 'http://localhost:3000'
  : 'https://cx-channel.onrender.com';

let activeFilter = 'all';
let allRequests  = [];

// ─── JWT HELPERS ─────────────────────────────────────
function getToken() { return localStorage.getItem('cx_token'); }

function authFetch(url, opts = {}) {
  return fetch(url, {
    ...opts,
    headers: {
      'Content-Type': 'application/json',
      'Authorization': 'Bearer ' + getToken(),
      ...(opts.headers || {})
    }
  });
}

// ─── CHECK LOGIN ─────────────────────────────────────
async function checkLogin() {
  try {
    const token = getToken();
    if (!token) { window.location.href = 'login.html'; return false; }

    const res  = await authFetch(`${API}/auth/me`);
    const data = await res.json();

    if (!data.user) {
      window.location.href = 'login.html';
      return false;
    }

    const initials = data.user.name.split(' ').map(n => n[0]).join('').toUpperCase();
    document.querySelector('.avatar').textContent = initials;
    return true;

  } catch (err) {
    window.location.href = 'login.html';
    return false;
  }
}

// ─── SOCKET.IO ───────────────────────────────────────
const socket = io('https://cx-channel.onrender.com');

socket.on('new_request', (request) => {
  allRequests.unshift(request);
  renderTable();
  updateStats();
  showLiveBanner(
    `New request from ${request.name}`,
    `${request.id} — ${request.subject}`,
    'new'
  );
});

socket.on('status_changed', (updated) => {
  const index = allRequests.findIndex(r => r.id === updated.id);
  if (index !== -1) allRequests[index] = updated;
  renderTable();
  updateStats();
  showLiveBanner(
    `Request ${updated.status}`,
    `${updated.id} — ${updated.name}`,
    updated.status
  );
});

socket.on('connect', () => {
  console.log('Connected to live updates');
  showConnectionDot(true);
});

socket.on('disconnect', () => {
  console.log('Disconnected from live updates');
  showConnectionDot(false);
});

// ─── LIVE BANNER ─────────────────────────────────────
function showLiveBanner(title, desc, type) {
  const colors = {
    new:      { bg: '#E6F1FB', color: '#0C447C', label: 'Live' },
    approved: { bg: '#E1F5EE', color: '#085041', label: 'Approved' },
    rejected: { bg: '#FCEBEB', color: '#791F1F', label: 'Rejected' },
    review:   { bg: '#FAEEDA', color: '#633806', label: 'Review' }
  };
  const c = colors[type] || colors.new;

  const banner = document.createElement('div');
  banner.style.cssText = `
    position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
    background: #fff; border: 1px solid #E2DDD6; border-radius: 12px;
    padding: 14px 20px; display: flex; align-items: center; gap: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.12); z-index: 500;
    font-family: 'DM Sans', sans-serif; min-width: 320px;
    animation: bannerIn 0.3s ease;
  `;
  banner.innerHTML = `
    <span style="background:${c.bg}; color:${c.color}; font-size:11px; font-weight:600;
      padding:3px 10px; border-radius:20px; white-space:nowrap;">${c.label}</span>
    <div style="flex:1;">
      <div style="font-size:14px; font-weight:600; color:#1A1714;">${title}</div>
      <div style="font-size:12px; color:#7A7570;">${desc}</div>
    </div>
    <button onclick="this.parentElement.remove()" style="background:none; border:none;
      cursor:pointer; font-size:16px; color:#7A7570; line-height:1;">✕</button>
  `;

  if (!document.getElementById('bannerStyle')) {
    const style = document.createElement('style');
    style.id = 'bannerStyle';
    style.textContent = `
      @keyframes bannerIn {
        from { transform: translateX(-50%) translateY(20px); opacity: 0; }
        to   { transform: translateX(-50%) translateY(0);    opacity: 1; }
      }
    `;
    document.head.appendChild(style);
  }

  document.body.appendChild(banner);
  setTimeout(() => banner.remove(), 5000);
}

// ─── CONNECTION DOT ──────────────────────────────────
function showConnectionDot(connected) {
  let dot = document.getElementById('liveDot');
  if (!dot) {
    dot = document.createElement('div');
    dot.id = 'liveDot';
    dot.style.cssText = `display: flex; align-items: center; gap: 6px; font-size: 12px; color: #7A7570;`;
    dot.innerHTML = `
      <span id="liveDotCircle" style="width:8px;height:8px;border-radius:50%;background:#E2DDD6;display:inline-block;"></span>
      <span id="liveDotText">Connecting...</span>`;
    const topbarRight = document.querySelector('.topbar-right') || document.querySelector('.topbar');
    if (topbarRight) topbarRight.prepend(dot);
  }
  document.getElementById('liveDotCircle').style.background = connected ? '#1D9E75' : '#E2DDD6';
  document.getElementById('liveDotText').textContent        = connected ? 'Live' : 'Reconnecting...';
}

// ─── LOAD REQUESTS ───────────────────────────────────
async function renderRequests() {
  try {
    const url      = activeFilter === 'all' ? `${API}/requests` : `${API}/requests?status=${activeFilter}`;
    const response = await authFetch(url);
    allRequests    = await response.json();
    renderTable();
    updateStats();
  } catch (err) {
    document.getElementById('requestsList').innerHTML = `
      <div style="padding:40px; text-align:center; color:#A32D2D; font-size:14px;">
        Cannot connect to server.
      </div>`;
  }
}

// ─── RENDER TABLE ────────────────────────────────────
function renderTable() {
  const list     = document.getElementById('requestsList');
  const filtered = activeFilter === 'all'
    ? allRequests
    : allRequests.filter(r => r.status === activeFilter);

  if (filtered.length === 0) {
    list.innerHTML = `
      <div style="padding:40px; text-align:center; color:#7A7570; font-size:14px;">
        No requests found. Click "+ New Request" to add one.
      </div>`;
    return;
  }

  list.innerHTML = filtered.map(r => `
    <div class="table-row">
      <div>${r.subject}<br/><small style="color:#7A7570">${r.id}</small></div>
      <div>${r.name}<br/><small style="color:#7A7570">${r.email}</small></div>
      <div><span class="priority-tag p-${r.priority.toLowerCase()}">${r.priority}</span></div>
      <div><span class="status-pill s-${r.status}">${r.status}</span></div>
      <div>
        ${r.status === 'pending' || r.status === 'review'
          ? `<button class="action-btn" onclick="approve('${r.id}')">Approve</button>`
          : '—'}
      </div>
    </div>
  `).join('');
}

// ─── STATS ───────────────────────────────────────────
function updateStats() {
  document.getElementById('statTotal').textContent    = allRequests.length;
  document.getElementById('statPending').textContent  = allRequests.filter(r => r.status === 'pending').length;
  document.getElementById('statApproved').textContent = allRequests.filter(r => r.status === 'approved').length;
}

// ─── FILTER ──────────────────────────────────────────
function filterRequests(status) {
  activeFilter = status;
  document.querySelectorAll('.nav-item').forEach(btn => btn.classList.remove('active'));
  event.currentTarget.classList.add('active');
  renderTable();
}

// ─── APPROVE ─────────────────────────────────────────
async function approve(id) {
  try {
    const response = await authFetch(`${API}/requests/${id}/status`, {
      method: 'PATCH',
      body:   JSON.stringify({ status: 'approved' })
    });
    const updated = await response.json();
    fireToasts(updated.name, updated.id, 'approved');
  } catch (err) {
    alert('Could not approve — check server.');
  }
}

// ─── MODAL ───────────────────────────────────────────
function openModal()  { document.getElementById('modalOverlay').classList.add('open');    }
function closeModal() { document.getElementById('modalOverlay').classList.remove('open'); }

// ─── SUBMIT NEW REQUEST ──────────────────────────────
async function submitRequest() {
  const name     = document.getElementById('fName').value.trim();
  const email    = document.getElementById('fEmail').value.trim();
  const subject  = document.getElementById('fSubject').value.trim();
  const priority = document.getElementById('fPriority').value;
  const details  = document.getElementById('fDetails').value.trim();

  if (!name || !email || !subject) {
    alert('Please fill in Name, Email, and Subject.');
    return;
  }

  try {
    const response = await fetch(`${API}/public/requests`, {
  headers: { 'Content-Type': 'application/json' },
      method: 'POST',
      body:   JSON.stringify({ name, email, subject, priority, details })
    });

    if (!response.ok) {
      const err = await response.json();
      alert('Error: ' + err.error);
      return;
    }

    const newRequest = await response.json();
    closeModal();
    ['fName','fEmail','fSubject','fDetails'].forEach(id => {
      document.getElementById(id).value = '';
    });
    fireToasts(newRequest.name, newRequest.id, 'submitted');

  } catch (err) {
    alert('Could not submit — check server.');
  }
}

// ─── LOGOUT ──────────────────────────────────────────
async function logout() {
  localStorage.removeItem('cx_token');
  localStorage.removeItem('cx_user');
  window.location.href = 'login.html';
}

// ─── TOASTS ──────────────────────────────────────────
function fireToasts(name, id, action) {
  const messages = {
    submitted: {
      team:     { title: 'New request received',      desc: `${id} from ${name}` },
      customer: { title: 'Request submitted!',        desc: `Hi ${name}, your request ${id} is received.` }
    },
    approved: {
      team:     { title: 'Request approved',          desc: `${id} has been approved` },
      customer: { title: 'Your request is approved!', desc: `Hi ${name}, ${id} has been approved.` }
    },
    rejected: {
      team:     { title: 'Request rejected',          desc: `${id} has been rejected` },
      customer: { title: 'Request update',            desc: `Hi ${name}, ${id} could not be approved.` }
    }
  };

  const m         = messages[action] || messages.submitted;
  const container = document.getElementById('toastContainer');

  [['team', m.team], ['customer', m.customer]].forEach(([type, msg], i) => {
    setTimeout(() => {
      const toast     = document.createElement('div');
      toast.className = `toast toast-${type}`;
      toast.innerHTML = `
        <div class="toast-title">${type === 'team' ? '🔵 Team' : '🟢 Customer'}: ${msg.title}</div>
        <div class="toast-desc">${msg.desc}</div>
      `;
      container.appendChild(toast);
      setTimeout(() => toast.remove(), 4000);
    }, i * 400);
  });
}

// ─── INIT ────────────────────────────────────────────
checkLogin().then(loggedIn => {
  if (loggedIn) renderRequests();
});