# AAA Cybersource module
Integrates [Drupal](https://www.drupal.org/home), [Webform](https://www.drupal.org/project/webform), and the [Cybersource](https://www.cybersource.com/en-us.html) payments API specifically for [Archives of American Art](https://aaa.si.edu).

# Installation
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
