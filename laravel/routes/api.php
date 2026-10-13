/* ==========================================================================
   api.js — the ONE place the Laravel backend is configured and called.
   Load this BEFORE store.js on every page (admin pages: ../js/api.js).

   Backend URL: change API_BASE below, or set window.UPADYAY_API_BASE in an
   inline <script> before this file (e.g. for staging/production).
   Auth: Laravel Sanctum bearer token. Token is the only thing kept client-side;
   the user and role always come from GET /auth/me.
   ========================================================================== */
(function(){
  const API_BASE = (window.UPADYAY_API_BASE || ((/^(localhost|127\.0\.0\.1)$/.test(location.hostname)) ? 'http://localhost:8000/api' : 'https://api.upaadhyay.com/api')).replace(/\/+$/, '');
  const TOKEN_KEY = 'upadyay_api_token';
  const ROLE_HINT_KEY = 'upadyay_role_hint';   // first-paint hint only; never used for access decisions

  const SITE_ROOT = (function(){
    try{ const s = document.currentScript.src; return s.substring(0, s.lastIndexOf('/js/') + 1); }
    catch(e){ return '/'; }
  })();

  class ApiError extends Error {
    constructor(message, status, errors){ super(message); this.status = status; this.errors = errors || null; }
  }

  function getToken(){ try{ return localStorage.getItem(TOKEN_KEY); }catch(e){ return null; } }
  function setToken(t){ try{ t ? localStorage.setItem(TOKEN_KEY, t) : localStorage.removeItem(TOKEN_KEY); }catch(e){} }
  function clearAuth(){
    setToken(null);
    try{ localStorage.removeItem(ROLE_HINT_KEY); }catch(e){}
    api.user = null;
  }

  /* Turn a Laravel error body into one readable message (validation errors included). */
  function messageFrom(body, status){
    if(body && body.errors){
      const first = Object.values(body.errors).flat()[0];
      if(first) return String(first);
    }
    if(body && body.message) return body.message;
    return status ? ('Request failed (' + status + ')') : 'Cannot reach the server. Check your connection and try again.';
  }

  function toast(msg){
    if(typeof showGenericToast === 'function' && document.getElementById('genericToast')){ showGenericToast(msg); return; }
    let el = document.getElementById('apiToast');
    if(!el){
      el = document.createElement('div'); el.id = 'apiToast';
      el.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#0B1F45;color:#fff;padding:10px 16px;border-radius:8px;font-size:13.5px;z-index:99999;max-width:90vw;box-shadow:0 8px 24px rgba(0,0,0,.25);';
      document.body.appendChild(el);
    }
    el.textContent = msg; el.style.display = 'block';
    clearTimeout(el._t); el._t = setTimeout(function(){ el.style.display = 'none'; }, 3500);
  }

  function loginUrl(){
    const inAdmin = /\/admin\//.test(location.pathname);
    return SITE_ROOT + (inAdmin ? 'admin/login.html' : 'pages/login.html');
  }
  function redirectToLogin(){
    if(/\/(login|register)\.html$/.test(location.pathname)) return;   // already on an auth page
    location.replace(loginUrl());
  }

  async function request(method, path, body, opts){
    opts = opts || {};
    const headers = { 'Accept': 'application/json' };
    if(body !== undefined && !(body instanceof FormData)) headers['Content-Type'] = 'application/json';
    const token = getToken();
    if(token) headers['Authorization'] = 'Bearer ' + token;

    let res;
    try{
      res = await fetch(API_BASE + path, {
        method: method, headers: headers,
        body: body === undefined ? undefined : (body instanceof FormData ? body : JSON.stringify(body))
      });
    }catch(e){
      throw new ApiError(messageFrom(null, 0), 0);
    }
    let data = null;
    try{ data = await res.json(); }catch(e){}

    if(res.ok) return data ? data.data : null;

    // 401 = missing/invalid/expired token: drop the session and go to login.
    if(res.status === 401 && !opts.noAuthRedirect){
      clearAuth();
      if(typeof api.onUnauthenticated === 'function') api.onUnauthenticated();
      redirectToLogin();
    }
    // 403 = logged in but not allowed (or blocked account).
    if(res.status === 403 && !opts.silent403) toast(messageFrom(data, 403));
    throw new ApiError(messageFrom(data, res.status), res.status, data && data.errors);
  }

  const api = {
    BASE: API_BASE, SITE_ROOT: SITE_ROOT, ApiError: ApiError,
    user: null,                    // filled by me()/login()/register()
    notice: '',                    // set by login() when the other account type was opened
    onUnauthenticated: null,
    getToken: getToken,
    hasToken: function(){ return !!getToken(); },
    roleHint: function(){ try{ return localStorage.getItem(ROLE_HINT_KEY); }catch(e){ return null; } },
    get:    function(p, o){ return request('GET', p, undefined, o); },
    post:   function(p, b, o){ return request('POST', p, b === undefined ? {} : b, o); },
    put:    function(p, b, o){ return request('PUT', p, b === undefined ? {} : b, o); },
    del:    function(p, o){ return request('DELETE', p, undefined, o); },

    _accept: function(payload){
      setToken(payload.token);
      api.user = payload.user;
      try{ localStorage.setItem(ROLE_HINT_KEY, payload.user.role); }catch(e){}
      return payload.user;
    },
    /* role ('candidate'|'company') = the login type the person picked. If the password matches, the account that
       was registered with this email opens whatever they picked; api.notice then says so. */
    login: async function(email, password, role){
      const body = { email: email, password: password };
      if(role) body.role = role;
      const d = await request('POST', '/auth/login', body, { noAuthRedirect: true });
      api.notice = (d && d.notice) || '';
      return api._accept(d);
    },
    /* role must be 'candidate' | 'company' (frontend 'seeker' is mapped here). */
    register: async function(fields){
      const body = Object.assign({}, fields);
      if(body.role === 'seeker') body.role = 'candidate';
      const d = await request('POST', '/auth/register', body, { noAuthRedirect: true });
      return api._accept(d);
    },
    logout: async function(){
      try{ if(getToken()) await request('POST', '/auth/logout', {}, { noAuthRedirect: true, silent403: true }); }
      catch(e){ /* token may already be invalid server-side; still clear locally */ }
      clearAuth();
    },
    /* Source of truth for "who am I". Resolves to the user, or null if not logged in. */
    me: async function(){
      if(!getToken()){ api.user = null; return null; }
      try{
        const u = await request('GET', '/auth/me', undefined, { noAuthRedirect: true, silent403: true });
        api.user = u;
        try{ localStorage.setItem(ROLE_HINT_KEY, u.role); }catch(e){}
        return u;
      }catch(e){
        if(e.status === 401 || e.status === 403){ clearAuth(); return null; }   // invalid/expired/blocked
        throw e;                                                                  // network/server error: keep token
      }
    },
    clearAuth: clearAuth,
    redirectToLogin: redirectToLogin,
    toast: toast,
    /* backend role -> frontend role name used throughout the existing UI */
    toUiRole: function(role){ return role === 'company' ? 'company' : (role === 'candidate' ? 'seeker' : role); }
  };
  /* ==========================================================================
     LOCAL MODE (temporary) — login/register/session stored in localStorage.
     No backend needed. To go back to Laravel later, this block is OFF by default now. To re-enable offline demo mode set
       window.UPADYAY_LOCAL = true;   (before api.js loads).
     Users live in localStorage key 'upadyay_local_users'.
     Seeded admin: admin@upadyay.com / admin123  (change/remove before launch)
     ========================================================================== */
  if(window.UPADYAY_LOCAL === true){   // opt-in only; live site uses the Laravel backend
    const USERS_KEY = 'upadyay_local_users';

    function loadUsers(){
      let u = [];
      try{ u = JSON.parse(localStorage.getItem(USERS_KEY)) || []; }catch(e){}
      if(!Array.isArray(u)) u = [];
      return u;
    }
    function saveUsers(u){ try{ localStorage.setItem(USERS_KEY, JSON.stringify(u)); }catch(e){} }

    async function hash(pw){
      try{
        if(window.crypto && crypto.subtle){
          const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode('upadyay:' + pw));
          return Array.from(new Uint8Array(buf)).map(function(b){ return b.toString(16).padStart(2,'0'); }).join('');
        }
      }catch(e){}
      let h = 5381; const t = 'upadyay:' + pw;                       // fallback (non-secure context / file://)
      for(let i = 0; i < t.length; i++) h = ((h << 5) + h + t.charCodeAt(i)) | 0;
      return 'x' + (h >>> 0).toString(16);
    }
    function publicUser(u){
      return { id: u.id, name: u.name, email: u.email, phone: u.phone || '', role: u.role,
               company: u.role === 'company' ? { company_name: u.company_name || '' } : null };
    }
    function tokenFor(u){ return 'local.' + u.id; }
    function fail(msg, status){ return new ApiError(msg, status || 422); }

    async function ensureAdmin(){
      const users = loadUsers();
      if(users.some(function(u){ return u.role === 'admin'; })) return;
      users.push({ id: 'u_admin', name: 'Admin', email: 'admin@upadyay.com',
                   password: await hash('admin123'), role: 'admin', created: Date.now() });
      saveUsers(users);
    }

    api.login = async function(email, password){
      await ensureAdmin();
      const em = String(email || '').trim().toLowerCase();
      const u = loadUsers().find(function(x){ return x.email === em; });
      if(!u || u.password !== await hash(password)) throw fail('Invalid email or password.', 401);
      return api._accept({ token: tokenFor(u), user: publicUser(u) });
    };

    api.register = async function(fields){
      await ensureAdmin();
      const f = Object.assign({}, fields);
      const role = (f.role === 'company') ? 'company' : 'candidate';   // admin can never be registered here
      const email = String(f.email || '').trim().toLowerCase();
      const name = String(f.name || '').trim();
      if(!name) throw fail('Name is required.');
      if(!/^\S+@\S+\.\S+$/.test(email)) throw fail('Enter a valid email address.');
      if(!f.password || String(f.password).length < 6) throw fail('Password must be at least 6 characters.');
      if(role === 'company' && !String(f.company_name || '').trim()) throw fail('Institution name is required.');
      const users = loadUsers();
      if(users.some(function(x){ return x.email === email; })) throw fail('An account with this email already exists.');
      const u = { id: 'u_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6),
                  name: name, email: email, phone: f.phone || '', role: role,
                  company_name: role === 'company' ? String(f.company_name).trim() : undefined,
                  password: await hash(f.password), created: Date.now() };
      users.push(u); saveUsers(users);
      return api._accept({ token: tokenFor(u), user: publicUser(u) });
    };

    api.logout = async function(){ clearAuth(); };

    api.me = async function(){
      const t = getToken();
      if(!t || t.indexOf('local.') !== 0){ if(t) clearAuth(); api.user = null; return null; }
      const id = t.slice(6);
      const u = loadUsers().find(function(x){ return x.id === id; });
      if(!u){ clearAuth(); return null; }
      api.user = publicUser(u);
      try{ localStorage.setItem(ROLE_HINT_KEY, u.role); }catch(e){}
      return api.user;
    };
  }

  /* ---------- Main institution <-> sub-branch (code based; main institution AND admin both approve) ---------- */
  api.branches = {
    lookup:   function(code){ return request('GET', '/institutions?code=' + encodeURIComponent(code), undefined, { noAuthRedirect: true }); },
    search:   function(q){ return request('GET', '/institutions?q=' + encodeURIComponent(q), undefined, { noAuthRedirect: true }); },
    // sub-branch side
    mine:     function(){ return request('GET', '/company/branch', undefined, { silent403: true }); },
    connect:  function(code){ return request('POST', '/company/branch/connect', { code: code }); },
    submit:   function(code){ return request('POST', '/company/branch/join', code ? { code: code } : {}); },
    withdraw: function(){ return request('DELETE', '/company/branch'); },
    // main institution side
    list:     function(){ return request('GET', '/company/branches', undefined, { silent403: true }); },
    view:     function(id){ return request('GET', '/company/branches/' + id); },
    decide:   function(id, action, reason){ return request('PUT', '/company/branches/' + id + '/' + action, reason ? { reason: reason } : {}); },
    setCode:  function(code){ return request('PUT', '/company/institution-code', code ? { code: code } : {}); },
    // admin side
    adminList:   function(status){ return request('GET', '/admin/branch-requests' + (status ? '?status=' + status : '')); },
    adminDecide: function(id, action, reason){ return request('PUT', '/admin/branch-requests/' + id + '/' + action, reason ? { reason: reason } : {}); }
  };

  window.api = api;
})();
