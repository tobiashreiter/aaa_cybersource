(function(Drupal, drupalSettings, Flex) {
  Drupal.behaviors.aaaWebformTemplates = {
    attach: async function(context, settings) {
      // Sets up the Microform.
      const json = await Drupal.behaviors.aaaWebformTemplates.fetchToken()
      const flex = new Flex(json);
      const myStyles = {
        'input': {
          'font-size': '14px',
          'font-family': 'helvetica, tahoma, calibri, sans-serif',
          'color': '#555'
        },
        ':focus': { 'color': 'blue' },
        ':disabled': { 'cursor': 'not-allowed' },
        'valid': { 'color': '#3c763d' },
        'invalid': { 'color': '#a94442' }
      }
      const microform = flex.microform({ styles: myStyles })
      const number = microform.createField('number', { placeholder: 'Enter card number' })
      const securityCode = microform.createField('securityCode', { placeholder: '•••' })

      // Identify the containers to replace.
      number.load('#edit-card-number')
      securityCode.load('#edit-cvn')

      // Update the submit button event listener.
      const button = document.querySelector('.webform-submission-form input[type="submit"]')
      button.addEventListener('click', Drupal.behaviors.aaaWebformTemplates.payButton)

      Drupal.behaviors.aaaWebformTemplates.microform = microform
    },
    fetchToken: async function() {
      let webform_id = drupalSettings.aaa_cybersource.webform

      // Alternative look-up. May not be necessary.
      if (!webform_id) {
        const webform = document.querySelector('.webform-submission-form')
        const id = webform.getAttribute('id')
        webform_id = id.replace('webform-submission-', '').replace('-add-form', '').replaceAll('-', '_')
      }

      const token = await fetch(`/admin/config/aaa/token/${webform_id}`)
      .then(function(res) {
        return res.json()
      })
      .catch(function(error) {
        console.error(error)
      })

      return token
    },
    payButton: function(event) {
      event.preventDefault()

      const options = {
        expirationMonth: document.querySelector('[data-drupal-selector="edit-expiration-month"]').value,
        expirationYear: document.querySelector('[data-drupal-selector="edit-expiration-year"]').value
      }

      Drupal.behaviors.aaaWebformTemplates.microform.createToken(options, function(error, token) {
        if (error) {
          console.error(error)
        } else {
          document.querySelector('input[data-drupal-selector="token"]').value = token

          if (document.querySelector('input[data-drupal-selector="token"]').value.length > 0) {
            document.querySelector('form.webform-submission-form').submit()
          } else {
            // Wait.
            setTimeout(function() {
              document.querySelector('input[data-drupal-selector="token"]').value
            }, 250)
          }
        }
      })
    }
  }
})(Drupal, drupalSettings, Flex)
