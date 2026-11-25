document.addEventListener('DOMContentLoaded', function () {
  var menuToggle = document.querySelector('.foh-menu-toggle');
  var primaryNav = document.getElementById('foh-primary-nav');

  if (menuToggle && primaryNav) {
    menuToggle.addEventListener('click', function () {
      var isOpen = primaryNav.classList.toggle('is-open');
      menuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      menuToggle.setAttribute('aria-label', isOpen ? 'إغلاق القائمة' : 'فتح القائمة');
    });
  }

  document.querySelectorAll('.foh-search-box').forEach(function (form) {
    form.addEventListener('submit', function () {
      form.classList.add('is-loading');
    });
  });

  document.querySelectorAll('.foh-search-box input[type="search"]').forEach(function (input) {
    input.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        input.value = '';
      }
    });
  });

  var revealItems = document.querySelectorAll(
    '.foh-section, .foh-lab-card, .foh-test-tile, .foh-detail-panel, .foh-page-head'
  );

  if ('IntersectionObserver' in window && revealItems.length) {
    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.15, rootMargin: '0px 0px -40px 0px' }
    );

    revealItems.forEach(function (item) {
      item.classList.add('foh-reveal');
      observer.observe(item);
    });
  }
});
