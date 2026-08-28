(function () {
  function ensureLoader() {
    let loader = document.querySelector('.app-loader');
    if (loader) return loader;

    loader = document.createElement('div');
    loader.className = 'app-loader';
    loader.innerHTML = [
      '<div class="loader-box">',
      '<div class="loader-mark"></div>',
      '<div class="loader-title">Memuat halaman</div>',
      '<div class="loader-bar"></div>',
      '</div>'
    ].join('');
    document.body.appendChild(loader);
    return loader;
  }

  function showLoader(text) {
    const loader = ensureLoader();
    const title = loader.querySelector('.loader-title');
    if (title && text) title.textContent = text;
    loader.classList.add('show');
  }

  window.showAppLoader = showLoader;

  document.addEventListener('DOMContentLoaded', function () {
    document.body.classList.add('app-ready');
    ensureLoader();

    document.querySelectorAll('a[href]').forEach(function (link) {
      link.addEventListener('click', function (event) {
        const href = link.getAttribute('href') || '';
        if (
          link.target === '_blank' ||
          href === '' ||
          href.startsWith('#') ||
          href.startsWith('javascript:') ||
          link.hasAttribute('download') ||
          href.includes('export=')
        ) {
          return;
        }
        showLoader('Memuat halaman');
      });
    });

    document.querySelectorAll('form').forEach(function (form) {
      form.addEventListener('submit', function () {
        form.classList.add('is-submitting');
        showLoader('Mengirim data');
      });
    });
  });

  window.addEventListener('pageshow', function () {
    document.querySelectorAll('.app-loader').forEach(function (loader) {
      loader.classList.remove('show');
    });
    document.body.classList.add('app-ready');
  });
})();
