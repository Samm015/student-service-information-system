<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title data-inertia>Student Services</title>
  <script>
    // Logged-out guard. Runs before React, independent of Inertia's history.
    // When this page is shown through Back/Forward (or restored from the
    // browser's back/forward cache), hide it until the server confirms the
    // session is still valid; otherwise go to the login page.
    (function () {
      var root = document.documentElement;
      function check(hide) {
        if (location.pathname === '/login') return;
        if (hide) root.style.visibility = 'hidden';
        fetch('/session-check', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (d && d.auth === false) { location.replace('/login'); } else { root.style.visibility = ''; }
          })
          .catch(function () { root.style.visibility = ''; });
      }
      var nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
      if (nav && nav.type === 'back_forward') check(true);
      window.addEventListener('pageshow', function (e) { if (e.persisted) check(true); });
      window.addEventListener('popstate', function () { check(false); });
    })();
  </script>
  @viteReactRefresh
  @vite(['resources/css/app.css', 'resources/js/app.jsx'])
  @inertiaHead
</head>
<body class="bg-slate-50 text-slate-900">
  @inertia
</body>
</html>
