(function (Drupal, once) {
  Drupal.behaviors.sangelPrintActions = {
    attach: function (context) {
      once('sangel-print-actions', '[data-sangel-print]', context).forEach(function (button) {
        button.addEventListener('click', function (event) {
          event.preventDefault();
          window.print();
        });
      });
    }
  };
})(Drupal, once);
