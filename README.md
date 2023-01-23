# AAA Cybersource module
Integrates [Drupal](https://www.drupal.org/home), [Webform](https://www.drupal.org/project/webform), and the [Cybersource](https://www.cybersource.com/en-us.html) payments API specifically for [Archives of American Art](https://aaa.si.edu).

## Requirements
This module extends the Drupal Webform module which must be installed.

## Installation
Require the module using composer which will call it from the github repository. It is also necessary to apply patches to the CyberSource PHP Rest Client so that it may be called properly from Drupal without error. In your `composer.json` file and assuming that `cweagans/composer-patches` is installed and enabled add the following:
```
"cybersource/rest-client-php": {
    "Remove include autoload.php": "web/modules/custom/aaa_cybersource/patches/autoload.patch",
    "Remove logging from Key Generator": "web/modules/custom/aaa_cybersource/patches/logging.patch"
}
```
If your drupal-custom-module is installed in another path, update the relative paths to point to the patch files.
Run `composer install` to apply the patches.
Otherwise you must edit this code manually in the vendor files and commit that change which may be difficult in a composer-managed site.

Enable the `aaa_cybersource` module.

## Configuration
The configuration base route is at `/admin/config/aaa`.

**Cybersource Form Settings**
This form configures the global and per-form settings related to CyberSource and other various options.

It is necessary to obtain a Merchant ID and a [jwt-certificate][1] from CyberSource.

When you add new forms their individual options will appear at the bottom of the page.

## Creating a new form
Create a new form from the Donation Webform Template installed by this module. Go to all Webform Templates (`admin/structure/webform/templates`) and select the Donation form that also has Category Cybersource. You will be presented with a form for some additional options and then to Save to create the new form. **It's necessary that Category remain Cybersource**.

All the necessary elements are already added to the form but most elements outside the "Payment Details" group can be changed and edited. The only other necessary element is an "amount" element which should return an integer or decimal amount of currency.

## Payment entity
Webform submissions will store incoming data from the forms. However it's not a good permanent solution to storing payment data because submissions can be deleted when forms are removed and because form submissions exist as a record of what the form receieved. The Payment entity will exist to record and track the payment and transaction information. They will not be removed if forms are deleted at a future date.

## Links
[1]: https://developer.cybersource.com/docs/cybs/en-us/payouts/developer/all/rest/payouts/authentication/createCert.html "JWT Certificate"