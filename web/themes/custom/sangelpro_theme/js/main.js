(function (Drupal, once) {
  Drupal.behaviors.sangelpro = {
    attach: function (context) {
      // Place pour de petits comportements (ex. loader sur CTA, etc.)
      once("sangelpro-cta", ".sp-card__cta a", context).forEach(function (el) {
        el.addEventListener("click", function () {
          el.classList.add("is-loading");
          setTimeout(function () {
            el.classList.remove("is-loading");
          }, 1200);
        });
      });
    },
  };
})(Drupal, once);
