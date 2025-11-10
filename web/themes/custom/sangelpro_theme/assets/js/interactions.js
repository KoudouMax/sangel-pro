/**
 * @file
 * Lightweight interaction helpers for SangelPro.
 */

((Drupal, once) => {
  Drupal.behaviors.sangelProInteractions = {
    attach(context) {
      once('sangelproToggle', '[data-sangelpro-toggle]', context).forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
          event.preventDefault();
          const selector = trigger.getAttribute('data-sangelpro-toggle');
          if (!selector) {
            return;
          }

          const scope = context.querySelector ? context : document;
          const target = scope.querySelector(selector) || document.querySelector(selector);
          if (target) {
            target.classList.toggle('hidden');
          }
        });
      });
    },
  };
})(Drupal, once);
