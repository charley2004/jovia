/* Jovia Network — sign-up, sign-in, forgot and reset password forms (talks to /api/auth) */
(function () {
  'use strict';

  const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
  const PHONE_RE = /^\+?[0-9\s\-()]{7,20}$/;

  // ── Show / hide password ──
  document.querySelectorAll('[data-toggle-password]').forEach((btn) => {
    const input = btn.parentElement.querySelector('input');
    btn.addEventListener('click', () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      btn.querySelector('use').setAttribute('href', show ? '#i-eye-off' : '#i-eye');
    });
  });

  const form = document.querySelector('.auth-form');
  if (!form) return;
  const status = form.querySelector('.form-status');
  const submit = form.querySelector('.btn-submit');
  const params = new URLSearchParams(window.location.search);

  // Referral links look like signup.html?ref=CODE
  const refInput = form.querySelector('#referral');
  if (refInput && params.get('ref')) refInput.value = params.get('ref').toUpperCase();

  // Already signed in? Skip the sign-in / sign-up forms.
  if (form.id === 'signin-form' || form.id === 'signup-form') {
    JoviaAPI.get('/auth/me').then((d) => { window.location.href = d.user.role === 'ADMIN' ? '/admin' : 'dashboard.html'; }, () => {});
  }

  function isValid(input) {
    const value = input.type === 'checkbox' ? input.checked : input.value.trim();
    if (input.required && !value) return false;
    if (input.type === 'checkbox') return true;
    if (!value) return true;
    if (input.type === 'email') return EMAIL_RE.test(value);
    if (input.dataset.validate === 'phone') return PHONE_RE.test(value);
    if (input.dataset.validate === 'email-or-phone') return EMAIL_RE.test(value) || PHONE_RE.test(value);
    if (input.minLength > 0) return value.length >= input.minLength;
    return true;
  }

  function check(input) {
    const field = input.closest('.field');
    if (!field) return true;
    const ok = isValid(input);
    field.classList.toggle('error', !ok);
    input.setAttribute('aria-invalid', String(!ok));
    return ok;
  }

  const inputs = form.querySelectorAll('input, select');
  inputs.forEach((input) => {
    const evt = input.type === 'checkbox' || input.tagName === 'SELECT' ? 'change' : 'blur';
    input.addEventListener(evt, () => check(input));
    input.addEventListener('input', () => {
      if (input.closest('.field.error')) check(input);
    });
  });

  function showStatus(message, kind) {
    status.textContent = message;
    status.className = `form-status show ${kind}`;
  }

  const handlers = {
    'signup-form': async (data) => {
      await JoviaAPI.post('/auth/signup', { ...data, terms: Boolean(data.terms) });
      showStatus('Account created. Taking you to your dashboard…', 'ok');
      setTimeout(() => { window.location.href = 'dashboard.html'; }, 600);
    },
    'signin-form': async (data) => {
      const { user } = await JoviaAPI.post('/auth/login', { identifier: data.identifier, password: data.password });
      showStatus('Signed in. Redirecting…', 'ok');
      setTimeout(() => { window.location.href = user.role === 'ADMIN' ? '/admin' : 'dashboard.html'; }, 400);
    },
    'forgot-form': async (data) => {
      const res = await JoviaAPI.post('/auth/forgot', { identifier: data.identifier });
      showStatus(res.message, 'ok');
    },
    'reset-form': async (data) => {
      const token = params.get('token');
      if (!token) throw new Error('This reset link is missing its token. Request a new one.');
      await JoviaAPI.post('/auth/reset', { token, password: data.password });
      showStatus('Password updated. Taking you to sign in…', 'ok');
      setTimeout(() => { window.location.href = 'signin.html'; }, 1200);
    },
  };

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    let firstBad = null;
    inputs.forEach((input) => {
      if (!check(input) && !firstBad) firstBad = input;
    });
    if (firstBad) {
      firstBad.focus();
      return;
    }

    const label = submit.textContent;
    submit.disabled = true;
    submit.textContent = 'Please wait…';
    try {
      await handlers[form.id](Object.fromEntries(new FormData(form)));
    } catch (err) {
      showStatus(err.message, 'err');
    } finally {
      submit.disabled = false;
      submit.textContent = label;
    }
  });

  document.querySelectorAll('[data-google]').forEach((btn) => {
    btn.addEventListener('click', () => {
      showStatus('Google sign-in is coming soon. Please use your email or phone number for now.', 'err');
    });
  });
})();
