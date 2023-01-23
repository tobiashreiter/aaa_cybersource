<?php

namespace Drupal\aaa_webform_templates\Plugin\WebformElement;

use Drupal\webform\Plugin\WebformElement\WebformCompositeBase;
use Drupal\aaa_webform_templates\Element\WebformMicroformElement as Element;

/**
 * Provides a 'webform_microform_element' element.
 *
 * @WebformElement(
 *   id = "webform_microform_element",
 *   label = @Translation("Webform Microform element"),
 *   description = @Translation("Provides a webform for Cybersource Microform."),
 *   category = @Translation("Cybersource"),
 *   composite = TRUE,
 *   multiline = TRUE,
 * )
 *
 * @see \Drupal\aaa_webform_templates\Element\WebformMicroformElement
 * @see \Drupal\webform\Plugin\WebformElementBase
 * @see \Drupal\webform\Plugin\WebformElementInterface
 * @see \Drupal\webform\Annotation\WebformElement
 */
class WebformMicroformElement extends WebformCompositeBase {

  /**
   * {@inheritdoc}
   */
  public function getCompositeElements() {
    $elements = Element::getCompositeElements([]);

    return $elements;
  }

}
