// Shared behavior for the site portal (attendance.php, stock.php)

// Collapsible secondary sections — collapsed by default on mobile so the
// primary recording form is reachable without scrolling past secondary tools.
function toggleCollapse(headerEl) {
  headerEl.closest('.collapsible').classList.toggle('is-open');
}

document.addEventListener('DOMContentLoaded', () => {
  if (window.matchMedia('(min-width: 641px)').matches) {
    document.querySelectorAll('.collapsible').forEach(el => el.classList.add('is-open'));
  }

  // Sticky mobile save bar: only show while the inline save button
  // hasn't been scrolled into view yet (i.e. it's still below the
  // fold — the exact case where the shortcut is useful while working
  // through a long worker list). A fixed bar covers the bottom of the
  // viewport at every scroll position, so it must hide once the
  // inline button is reachable or already scrolled past — otherwise
  // it would occlude whatever comes after it (e.g. the expanded "Add
  // Worker" section).
  const stickyBar  = document.querySelector('.mobile-save-bar');
  const sourceBtn  = document.getElementById('save-btn');
  if (stickyBar && sourceBtn) {
    const updateStickyBar = () => {
      const belowFold = sourceBtn.getBoundingClientRect().top > window.innerHeight;
      stickyBar.classList.toggle('is-visible', belowFold);
    };
    updateStickyBar();
    window.addEventListener('scroll', updateStickyBar, { passive: true });
    window.addEventListener('resize', updateStickyBar);
  }
});
