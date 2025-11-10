(function (Drupal, once) {
  Drupal.behaviors.sangelCartActions = {
    attach(context) {
      once('sangel-cart-quantity', '[data-sangel-cart-quantity]', context).forEach((input) => {
        const updateUrl = input.getAttribute('data-sangel-cart-update-url');
        if (!updateUrl) {
          return;
        }

        const normalize = () => {
          const rawValue = parseInt(input.value, 10);
          const normalized = Number.isFinite(rawValue) && rawValue >= 0 ? rawValue : 0;
          if (normalized !== rawValue) {
            input.value = normalized;
          }
          return normalized;
        };

        input.addEventListener('input', normalize);

        const ajaxSettings = {
          url: updateUrl,
          event: 'change',
          progress: { type: 'none' },
          beforeSend: function (xmlhttprequest, options) {
            const quantity = normalize();
            try {
              const urlObject = new URL(updateUrl, window.location.origin);
              urlObject.searchParams.set('quantity', String(quantity));
              const finalUrl = urlObject.pathname + urlObject.search;
              options.url = finalUrl;
              xmlhttprequest.url = finalUrl;
            }
            catch (error) {
              // Ignore malformed URLs; fallback silently.
            }
          },
        };

        new Drupal.Ajax(false, input, ajaxSettings);
      });

      once('sangel-cart-quantity-wrapper', '[data-sangel-cart-quantity-wrapper]', context).forEach((wrapper) => {
        const input = wrapper.querySelector('[data-sangel-cart-quantity]');
        if (!input) {
          return;
        }

        const step = parseInt(input.getAttribute('step') || '1', 10) || 1;
        const adjust = (delta) => {
          const current = parseInt(input.value, 10);
          const base = Number.isFinite(current) ? current : 0;
          const next = Math.max(0, base + delta);
          if (next !== base) {
            input.value = next;
            input.dispatchEvent(new Event('change', { bubbles: true }));
          }
        };

        const decrease = wrapper.querySelector('[data-cart-quantity-decrease]');
        const increase = wrapper.querySelector('[data-cart-quantity-increase]');

        if (decrease) {
          decrease.addEventListener('click', (event) => {
            event.preventDefault();
            adjust(-step);
          });
        }

        if (increase) {
          increase.addEventListener('click', (event) => {
            event.preventDefault();
            adjust(step);
          });
        }
      });
    },
  };
})(Drupal, once);
