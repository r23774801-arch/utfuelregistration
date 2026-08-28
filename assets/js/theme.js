(function () {
  const savedTheme = localStorage.getItem('solar-theme') || 'dark';
  document.documentElement.setAttribute('data-theme', savedTheme);

  function updateButtons(theme) {
    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
      button.setAttribute('aria-label', theme === 'light' ? 'Aktifkan mode gelap' : 'Aktifkan mode terang');
      button.setAttribute('aria-pressed', theme === 'light' ? 'true' : 'false');
      button.innerHTML = theme === 'light'
        ? '<span class="theme-toggle-icon"><i data-lucide="moon" size="16"></i></span><span class="theme-toggle-text">Mode Dark</span>'
        : '<span class="theme-toggle-icon"><i data-lucide="sun" size="16"></i></span><span class="theme-toggle-text">Mode Light</span>';
    });
    if (window.lucide) {
      window.lucide.createIcons();
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    updateButtons(document.documentElement.getAttribute('data-theme'));
    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
      button.addEventListener('click', function () {
        const current = document.documentElement.getAttribute('data-theme') || 'dark';
        const next = current === 'light' ? 'dark' : 'light';
        button.classList.remove('is-switching');
        void button.offsetWidth;
        button.classList.add('is-switching');
        document.documentElement.classList.add('theme-changing');
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem('solar-theme', next);
        updateButtons(next);
        window.setTimeout(() => {
          button.classList.remove('is-switching');
          document.documentElement.classList.remove('theme-changing');
        }, 520);
      });
    });
  });
})();
