(function(Drupal, drupalSettings) {
  Drupal.behaviors.aaaSettingsForm = {
    attach: async function(context) {
      if (context.querySelector('.form-element--type-text--uppercase')) {
        context.querySelectorAll('.form-element--type-text--uppercase').forEach(function(element) {
          Drupal.behaviors.aaaSettingsForm.textToUppercase(element)
          element.removeEventListener('keyup', Drupal.behaviors.aaaSettingsForm.textToUppercaseEvent)
          element.addEventListener('keyup', Drupal.behaviors.aaaSettingsForm.textToUppercaseEvent)
        })
      }
    },
    textToUppercaseEvent: function (event) {
      Drupal.behaviors.aaaSettingsForm.textToUppercase(event.target)
    },
    textToUppercase: function (input) {
      let value = input.value
      input.value = value.toUpperCase()
    }
  }
})(Drupal, drupalSettings)
