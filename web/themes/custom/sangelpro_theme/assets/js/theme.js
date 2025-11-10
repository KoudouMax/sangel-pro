/**
 * @file
 * Global theme behaviors for SangelPro.
 */

(function (Drupal) {
  let headerOffsetInitialized = false;

  const updateHeaderOffset = () => {
    const root = document.documentElement;
    const header = document.querySelector('.sangelpro-header');

    if (!root || !header) {
      return;
    }

    const headerRect = header.getBoundingClientRect();
    const computedOffset = headerRect.height + 24; // 24px spacing below header.
    root.style.setProperty('--sangel-header-offset', `${Math.ceil(computedOffset)}px`);
  };

  Drupal.behaviors.sangelProTheme = {
    attach(context) {
      if (!headerOffsetInitialized) {
        headerOffsetInitialized = true;
        updateHeaderOffset();
        window.addEventListener('resize', updateHeaderOffset, { passive: true });
        window.addEventListener('load', updateHeaderOffset, { once: true });
      }

      updateHeaderOffset();
    },
  };
})(Drupal);
