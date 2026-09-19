/**
 * FF Arena - Production App JS - 5591 bytes target expanded with full features
 * Features: mobile nav, avatar preview, internet check, offline detection, toast, focus management
 */

(function() {
  'use strict';

  // Mobile Navigation Toggle
  function initMobileNav() {
    const toggle = document.querySelector('[data-mobile-toggle]');
    const nav = document.querySelector('[data-mobile-nav]');
    if (!toggle || !nav) return;

    toggle.addEventListener('click', () => {
      const isOpen = nav.classList.contains('open');
      nav.classList.toggle('open', !isOpen);
      toggle.setAttribute('aria-expanded', String(!isOpen));
      toggle.setAttribute('aria-label', isOpen ? 'Open menu' : 'Close menu');
    });

    // Close on outside click
    document.addEventListener('click', (e) => {
      if (!nav.contains(e.target) && !toggle.contains(e.target)) {
        nav.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
      }
    });

    // Close on escape
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && nav.classList.contains('open')) {
        nav.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.focus();
      }
    });
  }

  // Avatar Preview
  function initAvatarPreview() {
    const inputs = document.querySelectorAll('[data-avatar-input]');
    inputs.forEach(input => {
      input.addEventListener('change', (e) => {
        const file = e.target.files[0];
        if (!file) return;

        // Validate file type
        if (!file.type.startsWith('image/')) {
          showToast('Please select a valid image file', 'error');
          return;
        }

        // Validate size (max 2MB)
        if (file.size > 2 * 1024 * 1024) {
          showToast('Image must be less than 2MB', 'error');
          return;
        }

        const preview = document.querySelector(input.dataset.previewTarget || '[data-avatar-preview]');
        if (!preview) return;

        const reader = new FileReader();
        reader.onload = (ev) => {
          if (preview.tagName === 'IMG') {
            preview.src = ev.target.result;
          } else {
            preview.style.backgroundImage = `url(${ev.target.result})`;
            preview.innerHTML = '';
          }
          // Show remove button
          const removeBtn = document.querySelector('[data-avatar-remove]');
          if (removeBtn) removeBtn.style.display = 'inline-flex';
        };
        reader.readAsDataURL(file);
      });
    });

    // Avatar remove
    const removeBtns = document.querySelectorAll('[data-avatar-remove]');
    removeBtns.forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        const input = document.querySelector('[data-avatar-input]');
        const preview = document.querySelector('[data-avatar-preview]');
        if (input) input.value = '';
        if (preview) {
          if (preview.tagName === 'IMG') {
            preview.src = preview.dataset.fallback || '';
          } else {
            preview.style.backgroundImage = '';
            preview.innerHTML = preview.dataset.initials || '';
          }
        }
        btn.style.display = 'none';
        // Set hidden flag
        const flag = document.querySelector('[data-avatar-remove-flag]');
        if (flag) flag.value = '1';
      });
    });
  }

  // Internet / Offline Detection - Production Ready
  function initInternetCheck() {
    const banner = document.getElementById('offline-banner');
    const statusEls = document.querySelectorAll('[data-internet-status]');
    let isOnline = navigator.onLine;
    let checkInterval = null;
    let lastCheck = 0;

    function updateStatus(online, checking = false) {
      isOnline = online;
      
      // Update banner
      if (banner) {
        if (!online) {
          banner.classList.add('show');
          banner.setAttribute('aria-hidden', 'false');
        } else {
          banner.classList.remove('show');
          banner.setAttribute('aria-hidden', 'true');
        }
      }

      // Update all status indicators
      statusEls.forEach(el => {
        el.classList.remove('online', 'offline', 'checking');
        if (checking) {
          el.classList.add('checking');
          el.innerHTML = '<span class="dot"></span> Checking...';
          el.setAttribute('aria-label', 'Checking internet connection');
        } else if (online) {
          el.classList.add('online');
          el.innerHTML = '<span class="dot" style="background: var(--success)"></span> Online';
          el.setAttribute('aria-label', 'Online');
        } else {
          el.classList.add('offline');
          el.innerHTML = '<span class="dot" style="background: var(--danger)"></span> Offline';
          el.setAttribute('aria-label', 'Offline - check your connection');
        }
      });

      // Disable forms when offline if marked
      document.querySelectorAll('[data-require-online]').forEach(el => {
        el.disabled = !online;
        if (!online) {
          el.setAttribute('title', 'Requires internet connection');
        } else {
          el.removeAttribute('title');
        }
      });
    }

    async function checkConnectivity() {
      const now = Date.now();
      if (now - lastCheck < 5000) return; // throttle 5s
      lastCheck = now;

      updateStatus(isOnline, true);

      try {
        // Use navigator.onLine first
        if (!navigator.onLine) {
          updateStatus(false);
          return;
        }

        // Try fetch to reliable endpoint with cache bust
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 5000);
        
        const response = await fetch('/up?t=' + Date.now(), {
          method: 'HEAD',
          cache: 'no-store',
          signal: controller.signal,
        });
        
        clearTimeout(timeout);
        updateStatus(response.ok);
      } catch (e) {
        // If fetch fails but navigator says online, we are offline or server down
        // For UX, treat as offline if fetch fails
        console.warn('Connectivity check failed:', e.message);
        // Only mark offline if navigator also says offline or fetch consistently fails
        // Keep previous state if uncertain, but show warning
        if (!navigator.onLine) {
          updateStatus(false);
        } else {
          // Server might be down, but internet might be ok - keep online but log
          updateStatus(true);
        }
      }
    }

    // Initial check
    updateStatus(navigator.onLine);
    
    // Event listeners
    window.addEventListener('online', () => {
      console.log('Browser online event');
      checkConnectivity();
      showToast('Back online', 'success');
    });

    window.addEventListener('offline', () => {
      console.log('Browser offline event');
      updateStatus(false);
      showToast('You are offline - check your connection', 'warning');
    });

    // Periodic check every 30s
    checkInterval = setInterval(checkConnectivity, 30000);

    // Check on visibility change
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) {
        checkConnectivity();
      }
    });

    // Expose for manual trigger
    window.FFArena = window.FFArena || {};
    window.FFArena.checkInternet = checkConnectivity;
    window.FFArena.isOnline = () => isOnline;

    // Initial async check after 1s
    setTimeout(checkConnectivity, 1000);
  }

  // Toast System
  function showToast(message, type = 'info', duration = 4000) {
    const container = document.getElementById('toast-container') || createToastContainer();
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');
    
    const icons = {
      success: '✓',
      error: '✕',
      warning: '⚠',
      info: 'ℹ'
    };
    
    toast.innerHTML = `
      <div style="display: flex; gap: 10px; align-items: flex-start;">
        <span style="font-weight: 700;">${icons[type] || icons.info}</span>
        <span style="flex: 1;">${escapeHtml(message)}</span>
        <button onclick="this.closest('.toast').remove()" aria-label="Dismiss" style="background: none; border: none; color: inherit; cursor: pointer; padding: 0; font-size: 16px;">×</button>
      </div>
    `;

    container.appendChild(toast);

    // Auto remove
    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transform = 'translateX(100%)';
      setTimeout(() => toast.remove(), 300);
    }, duration);

    return toast;
  }

  function createToastContainer() {
    const container = document.createElement('div');
    container.id = 'toast-container';
    container.className = 'toast-container';
    container.setAttribute('aria-live', 'polite');
    container.setAttribute('aria-atomic', 'false');
    document.body.appendChild(container);
    return container;
  }

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  // Form Enhancements
  function initForms() {
    // Auto-hide alerts after 5s
    document.querySelectorAll('.alert').forEach(alert => {
      if (alert.dataset.persistent === 'true') return;
      setTimeout(() => {
        alert.style.transition = 'opacity 0.3s ease';
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 300);
      }, 5000);
    });

    // Confirm dangerous actions
    document.querySelectorAll('[data-confirm]').forEach(btn => {
      btn.addEventListener('click', (e) => {
        const message = btn.dataset.confirm || 'Are you sure?';
        if (!confirm(message)) {
          e.preventDefault();
          e.stopPropagation();
        }
      });
    });

    // Password visibility toggle
    document.querySelectorAll('[data-password-toggle]').forEach(toggle => {
      toggle.addEventListener('click', () => {
        const input = document.querySelector(toggle.dataset.target || 'input[type=password]');
        if (!input) return;
        const isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';
        toggle.textContent = isPassword ? 'Hide' : 'Show';
        toggle.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
      });
    });
  }

  // Keyboard Shortcuts
  function initKeyboard() {
    document.addEventListener('keydown', (e) => {
      // Focus search with /
      if (e.key === '/' && !e.target.matches('input, textarea, select')) {
        const search = document.querySelector('[data-search-input]');
        if (search) {
          e.preventDefault();
          search.focus();
        }
      }
    });
  }

  // Avatar Fallback (initials)
  function initAvatarFallbacks() {
    document.querySelectorAll('.avatar img').forEach(img => {
      img.addEventListener('error', () => {
        const fallback = img.nextElementSibling;
        if (fallback && fallback.classList.contains('avatar-fallback')) {
          img.style.display = 'none';
          fallback.style.display = 'grid';
        } else {
          // Create fallback
          const parent = img.parentElement;
          const initials = parent.dataset.initials || img.alt?.substring(0,2) || '??';
          const div = document.createElement('div');
          div.className = 'avatar-fallback';
          div.textContent = initials.toUpperCase();
          img.style.display = 'none';
          parent.appendChild(div);
        }
      });
    });
  }

  // Init All
  function init() {
    initMobileNav();
    initAvatarPreview();
    initInternetCheck();
    initForms();
    initKeyboard();
    initAvatarFallbacks();

    // Expose utilities globally
    window.FFArena = window.FFArena || {};
    window.FFArena.showToast = showToast;
    
    console.log('FF Arena JS initialized - Internet check active, Avatar ready');
  }

  // DOM Ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Service Worker registration (optional, non-blocking)
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      // Only register if sw.js exists - don't fail if missing
      fetch('/sw.js', { method: 'HEAD' }).then(res => {
        if (res.ok) {
          navigator.serviceWorker.register('/sw.js').catch(err => {
            console.warn('SW registration failed:', err);
          });
        }
      }).catch(() => {});
    });
  }
})();
