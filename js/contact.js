/* Jovia Network — Contact page form: validates, then sends the message to /api/contact */
(function () {
  'use strict';

  var form = document.getElementById('contact-form');
  if (!form || !window.JoviaAPI) return;
  var status = form.querySelector('.form-status');
  var submit = form.querySelector('.btn-submit');

  function setStatus(kind, message) {
    status.textContent = message;
    status.className = 'form-status show ' + kind;
  }

  function check(id, ok) {
    var field = document.getElementById(id).closest('.field');
    field.classList.toggle('error', !ok);
    return ok;
  }

  // Signed-in members: fill in their name and email
  JoviaAPI.get('/auth/me').then(function (d) {
    var u = d.user;
    if (!form.name.value) form.name.value = (u.firstName + ' ' + u.lastName).trim();
    if (!form.email.value) form.email.value = u.email;
    if (!form.phone.value && u.phone) form.phone.value = u.phone;
  }, function () {});

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var v = {
      name: form.name.value.trim(),
      email: form.email.value.trim(),
      phone: form.phone.value.trim(),
      topic: form.topic.value,
      message: form.message.value.trim(),
      website: form.website.value
    };
    var ok = [
      check('c-name', v.name.length >= 2),
      check('c-email', /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.email)),
      check('c-topic', !!v.topic),
      check('c-message', v.message.length >= 10)
    ].every(Boolean);
    if (!ok) {
      setStatus('err', 'Please fix the highlighted fields.');
      return;
    }
    submit.disabled = true;
    submit.textContent = 'Sending…';
    JoviaAPI.post('/contact', v).then(function () {
      form.reset();
      setStatus('ok', 'Thank you — your message has been sent. We will reply to ' + v.email + ' by email.');
    }, function (err) {
      setStatus('err', err.message);
    }).then(function () {
      submit.disabled = false;
      submit.textContent = 'Send message';
    });
  });
})();
