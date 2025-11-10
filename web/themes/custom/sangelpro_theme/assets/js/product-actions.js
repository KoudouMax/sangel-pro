(function (Drupal, once) {
  Drupal.behaviors.sangelProductActions = {
    attach(context) {
      once("sangel-product-action", "a[data-sangel-ajax-url]", context).forEach(
        (element) => {
          const baseUrl = element.getAttribute("data-sangel-ajax-url");
          if (!baseUrl) {
            return;
          }

          const quantitySelector = element.getAttribute(
            "data-sangel-ajax-quantity"
          );

          const ajaxSettings = {
            url: baseUrl,
            event: "click",
            progress: { type: "none" },
            beforeSend: function (xmlhttprequest, options) {
              if (!quantitySelector) {
                return;
              }

              const quantityElement = document.querySelector(quantitySelector);
              if (!quantityElement) {
                return;
              }

              const rawValue = parseInt(quantityElement.value, 10);
              const quantity = Number.isFinite(rawValue) && rawValue > 0 ? rawValue : 1;

              try {
                const urlObject = new URL(baseUrl, window.location.origin);
                urlObject.searchParams.set("quantity", quantity);
                const finalUrl = urlObject.pathname + urlObject.search;

                options.url = finalUrl;
                xmlhttprequest.url = finalUrl;
              } catch (error) {
                // Ignore malformed URLs; fallback to base URL.
              }
            },
          };

          // Installe la mécanique AJAX Drupal sur le lien.
          new Drupal.Ajax(false, element, ajaxSettings);
        }
      );

      once("sangel-product-quantity", "[data-product-quantity]", context).forEach(
        (input) => {
          const formatter = new Intl.NumberFormat("fr-CI", {
            style: "decimal",
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
          });

          const updateTotal = () => {
            const price = parseFloat(
              input.getAttribute("data-product-price") || "0"
            );
            if (!Number.isFinite(price)) {
              return;
            }

            const rawValue = parseInt(input.value, 10);
            const quantity = Number.isFinite(rawValue) && rawValue > 0 ? rawValue : 1;
            if (quantity !== rawValue) {
              input.value = quantity;
            }

            const wrapper = input.closest(".product-full__purchase");
            if (!wrapper) {
              return;
            }

            const target = wrapper.querySelector("[data-product-total-value]");
            if (!target) {
              return;
            }

            const total = price * quantity;
            const formatted = formatter.format(total);

            target.textContent = `${formatted}\u00A0F\u00A0CFA`;
          };

          input.addEventListener("input", updateTotal);
          input.addEventListener("change", updateTotal);
          updateTotal();
        }
      );
    },
  };
})(Drupal, once);

// Commande AJAX "sangelUpdateCounter" (garde ton implémentation)
Drupal.AjaxCommands.prototype.sangelUpdateCounter = function (ajax, response) {
  var container = document.querySelector(response.selector);
  if (!container) return;

  var link = container.querySelector(
    ".sangel-header-counter__link, .sangel-header-counter__link--active"
  );
  var icon =
    container.querySelector(".sangel-header-counter__icon") || container;
  var badge = container.querySelector(".sangel-header-counter__badge");
  var srOnly = container.querySelector(".sr-only");

  if (response.count > 0) {
    if (!badge) {
      badge = document.createElement("span");
      badge.className = "sangel-header-counter__badge";
      badge.setAttribute("aria-hidden", "true");
      icon.appendChild(badge);
    }
    badge.textContent = response.count;

    if (!srOnly) {
      srOnly = document.createElement("span");
      srOnly.className = "sr-only";
      srOnly.setAttribute("aria-live", "polite");
      icon.appendChild(srOnly);
    }
    srOnly.textContent = Drupal.t("Nombre d’éléments : @count", {
      "@count": response.count,
    });

    if (link) link.classList.add("sangel-header-counter__link--active");
  } else {
    if (badge && badge.parentNode) badge.parentNode.removeChild(badge);
    if (srOnly) srOnly.textContent = Drupal.t("Nombre d’éléments : 0");
    if (link) link.classList.remove("sangel-header-counter__link--active");
  }
};
