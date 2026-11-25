(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var forms = document.querySelectorAll('.htd-confirm-form');
    forms.forEach(function (form) {
      form.addEventListener('submit', function (e) {
        var msg = form.getAttribute('data-confirm') || 'Are you sure?';
        if (!window.confirm(msg)) {
          e.preventDefault();
        }
      });
    });
  });
})();
