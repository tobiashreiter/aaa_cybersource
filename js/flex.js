(function(Drupal, drupalSettings, Flex) {
  Drupal.behaviors.aaaWebformTemplates = {
    attach: async function(context, settings) {
      // Sets up the Microform.
      const json = await Drupal.behaviors.aaaWebformTemplates.fetchToken()
      const flex = new Flex(json);
      const myStyles = {
        'input': {
          'font-size': '16px',
          'font-family': '"GT Walsheim", Helvetica, Arial, sans-serif',
          'color': '#495057'
        },
        ':focus': { 'color': 'black' },
        ':disabled': { 'cursor': 'not-allowed' },
        'valid': { 'color': '#46BA69' },
        'invalid': { 'color': '#EE2D24' }
      }
      const microform = flex.microform({ styles: myStyles })
      const number = microform.createField('number', { placeholder: 'Enter card number' })
      const securityCode = microform.createField('securityCode', { placeholder: '•••' })

      // Identify the containers to replace.
      number.load('#edit-card-number')
      securityCode.load('#edit-cvn')

      number.on('change', function(data) {
        const couldBeValid = data.couldBeValid
        const empty = data.empty

        if (empty === false && couldBeValid === false) {
          document.querySelector('#edit-card-number').parentElement.querySelector('#card-number-notification').innerHTML = 'Credit card number is invalid.'
        }
        else {
          document.querySelector('#edit-card-number').parentElement.querySelector('#card-number-notification').innerHTML = ''
        }

        if (data.card.length === 1) {
          const type = data.card[0].name === 'amex' ? 'american express' : data.card[0].name
          const currentTypeInputValue = document.querySelector('input[name="card_type"]:checked')?.value
          const majorTypes = ['amex', 'discover', 'mastercard', 'visa']

          if (majorTypes.includes(type) === true && type !== currentTypeInputValue) {
            document.querySelector('input[name="card_type"][value="' + type + '"]').checked = true
          }
        }
        else if (data.card.length === 0 || couldBeValid === false) {
          document.querySelectorAll('input[name="card_type"]').forEach(c => c.checked = 0)
        }
      })

      // Update the submit button event listener.
      const button = document.querySelector('.webform-submission-form input[type="submit"]')
      button.addEventListener('click', Drupal.behaviors.aaaWebformTemplates.payButton)

      Drupal.behaviors.aaaWebformTemplates.microform = microform

      // Add aria-invalid to all fields which are marked as required.
      if (context.querySelectorAll('input[required]').length > 0) {
        context.querySelectorAll('.form-control[required="required"]').forEach(function (i) {
          i.setAttribute('aria-invalid', false)
        })
      }
    },
    fetchToken: async function() {
      let webform_id = drupalSettings.aaa_cybersource.webform

      // Alternative look-up. May not be necessary.
      if (!webform_id) {
        const webform = document.querySelector('.webform-submission-form')
        const id = webform.getAttribute('id')
        webform_id = id.replace('webform-submission-', '').replace('-add-form', '').replaceAll('-', '_')
      }

      const token = await fetch(`/admin/config/cybersource/token/${webform_id}`)
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

      event.target.classList.toggle('disabled', true)
      event.target.classList.toggle('submitting', true)

      const options = {
        expirationMonth: document.querySelector('[data-drupal-selector="edit-expiration-month"]').value,
        expirationYear: document.querySelector('[data-drupal-selector="edit-expiration-year"]').value
      }

      Drupal.behaviors.aaaWebformTemplates.microform.createToken(options, function(error, token) {
        if (error) {
          console.error(error)

          event.target.classList.toggle('disabled', false)
          event.target.classList.toggle('submitting', false)
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
