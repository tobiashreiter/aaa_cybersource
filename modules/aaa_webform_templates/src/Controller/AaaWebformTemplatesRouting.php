<?php

namespace Drupal\aaa_webform_templates\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Returns responses for Cybersource Webform Templates routes.
 */
class AaaWebformTemplatesRouting extends ControllerBase {

  public function routeToDonationTemplates() {
    $url = Url::fromRoute('entity.webform.templates.manage', [], ['query' => ['category' => 'Cybersource']])->toString();
    return new RedirectResponse($url, 307);
  }
}
