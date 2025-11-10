(function (Drupal, once) {
  Drupal.behaviors.sangelCatalogImportFeedback = {
    attach(context) {
      once(
        "sangel-catalog-import-feedback",
        'form[data-drupal-selector="sangel-catalog-commercial-feed-import"]',
        context
      ).forEach((form) => {
        const submit =
          form.querySelector('button[type="submit"]') ||
          form.querySelector('input[type="submit"]');
        if (!submit) {
          return;
        }

        const indicator = form.querySelector("[data-sangel-import-status]");
        const originalLabel =
          submit.tagName === "BUTTON" ? submit.innerHTML : submit.value;
        const processingLabel = submit.getAttribute(
          "data-loading-label"
        ) || Drupal.t("Import en cours...");

        form.addEventListener("submit", () => {
          submit.setAttribute("disabled", "disabled");
          submit.setAttribute("aria-busy", "true");
          submit.classList.add("is-loading");

          if (submit.tagName === "BUTTON") {
            submit.innerHTML =
              '<span class="sangel-catalog-import__button-spinner" aria-hidden="true"></span>' +
              '<span class="sangel-catalog-import__button-label">' +
              processingLabel +
              "</span>";
          } else {
            submit.value = processingLabel;
          }

          if (indicator) {
            indicator.classList.add("is-active");
            indicator.setAttribute("aria-hidden", "false");
          }
        });

        form.addEventListener("drupalFormRebuild", () => {
          if (submit.tagName === "BUTTON") {
            submit.innerHTML = originalLabel;
          } else {
            submit.value = originalLabel;
          }
          submit.removeAttribute("aria-busy");
          submit.classList.remove("is-loading");
        });
      });
    },
  };
})(Drupal, once);
