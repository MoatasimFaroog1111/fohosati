document.addEventListener('DOMContentLoaded', function () {
  var menuToggle = document.querySelector('.foh-menu-toggle');
  var primaryNav = document.getElementById('foh-primary-nav');

  if (menuToggle && primaryNav) {
    menuToggle.addEventListener('click', function () {
      var isOpen = primaryNav.classList.toggle('is-open');
      menuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      menuToggle.setAttribute('aria-label', isOpen ? 'إغلاق القائمة' : 'فتح القائمة');
    });

    primaryNav.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () {
        primaryNav.classList.remove('is-open');
        menuToggle.setAttribute('aria-expanded', 'false');
        menuToggle.setAttribute('aria-label', 'فتح القائمة');
      });
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

  var faqItems = document.querySelectorAll('.foh-faq-item');
  faqItems.forEach(function (item) {
    item.addEventListener('toggle', function () {
      if (!item.open) {
        return;
      }
      faqItems.forEach(function (other) {
        if (other !== item) {
          other.open = false;
        }
      });
    });
  });

  var contactForm = document.querySelector('.foh-contact-form');
  if (contactForm) {
    contactForm.addEventListener('submit', function (event) {
      event.preventDefault();
      var email = contactForm.getAttribute('data-contact-email') || '';
      if (!email) {
        return;
      }

      var name = contactForm.querySelector('[name="name"]').value.trim();
      var phone = contactForm.querySelector('[name="phone"]').value.trim();
      var senderEmail = contactForm.querySelector('[name="email"]').value.trim();
      var type = contactForm.querySelector('[name="type"]').value.trim();
      var message = contactForm.querySelector('[name="message"]').value.trim();

      if (!name || !phone || !message) {
        return;
      }

      var subject = encodeURIComponent('طلب جديد عبر نموذج فحوصاتي: ' + type);
      var body = encodeURIComponent(
        'الاسم: ' + name + '\n' +
        'الهاتف: ' + phone + '\n' +
        'البريد: ' + (senderEmail || 'غير مذكور') + '\n' +
        'نوع الطلب: ' + type + '\n\n' +
        'الرسالة:\n' + message
      );

      window.location.href = 'mailto:' + email + '?subject=' + subject + '&body=' + body;
    });
  }
});
