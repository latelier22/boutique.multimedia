document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.toggle-desc').forEach(function (container) {
      const short = container.querySelector('.short');
      const full = container.querySelector('.full');
      const showMore = container.querySelector('.show-more');
      const showLess = container.querySelector('.show-less');

      showMore.addEventListener('click', function (e) {
        e.preventDefault();
        short.style.display = 'none';
        full.style.display = 'inline';
      });

      showLess.addEventListener('click', function (e) {
        e.preventDefault();
        full.style.display = 'none';
        short.style.display = 'inline';
      });
    });
  });