// Back-office myClermont : petites améliorations, tout fonctionne aussi sans JavaScript.
(function () {
  'use strict';
  var csrf = document.querySelector('input[name=_csrf]');

  // Menu mobile
  var toggle = document.querySelector('[data-toggle-menu]');
  if (toggle) toggle.addEventListener('click', function () { document.getElementById('sidebar').classList.toggle('open'); });

  // Confirmation avant suppression
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) { if (!window.confirm(form.dataset.confirm)) e.preventDefault(); });
  });

  // Filtres appliqués dès qu'on change une liste déroulante
  document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { el.form.submit(); });
  });

  // Ligne de tableau cliquable
  document.querySelectorAll('tr[data-href]').forEach(function (tr) {
    tr.addEventListener('click', function (e) { if (!e.target.closest('a, button, form')) window.location = tr.dataset.href; });
  });

  // Pastilles de couleur (pas de style en ligne : CSP stricte)
  document.querySelectorAll('.swatch[data-color]').forEach(function (s) {
    if (/^#[0-9a-f]{6}$/i.test(s.dataset.color)) s.style.background = s.dataset.color;
  });

  // Envoi d'image : remplit le champ URL associé
  document.querySelectorAll('[data-upload]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.createElement('input');
      input.type = 'file'; input.accept = 'image/jpeg,image/png,image/webp';
      input.addEventListener('change', function () {
        if (!input.files.length) return;
        var data = new FormData();
        data.append('file', input.files[0]);
        data.append('_csrf', csrf ? csrf.value : '');
        btn.disabled = true; btn.textContent = 'Envoi…';
        fetch('/admin/upload', { method: 'POST', body: data, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (json) {
            if (!json.url) throw new Error(json.error || 'Envoi échoué');
            var field = document.getElementById(btn.dataset.upload);
            field.value = json.url;
            var img = btn.parentNode.querySelector('img.thumb') || document.createElement('img');
            img.className = 'thumb'; img.src = json.url; btn.parentNode.appendChild(img);
          })
          .catch(function (err) { window.alert(err.message); })
          .finally(function () { btn.disabled = false; btn.textContent = 'Envoyer une image…'; });
      });
      input.click();
    });
  });
})();
