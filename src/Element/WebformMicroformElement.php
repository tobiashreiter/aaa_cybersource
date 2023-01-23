<?php

namespace Drupal\aaa_cybersource\Element;

use Drupal\webform\Element\WebformCompositeBase;

/**
 * Provides a 'webform_microform_element'.
 *
 * @FormElement("webform_microform_element")
 */
class WebformMicroformElement extends WebformCompositeBase {

  /**
   * {@inheritdoc}
   */
  public static function getCompositeElements(array $element) {
    return [
      'card-number-label' => [
        '#type' => 'html_tag',
        '#tag' => 'label',
        '#value' => t('Card Number'),
        '#attributes' => [
          'class' => ['js-form-required form-required'],
        ],
      ],
      'card-number' => [
        '#type' => 'container',
        '#id' => 'edit-card-number',
        '#attributes' => [
          'class' => ['form-control'],
        ],
      ],
      'cvn-label' => [
        '#type' => 'html_tag',
        '#tag' => 'label',
        '#value' => t('CVN'),
        '#attributes' => [
          'class' => ['js-form-required form-required'],
        ],
      ],
      'cvn' => [
        '#type' => 'container',
        '#id' => 'edit-cvn',
        '#attributes' => [
          'class' => ['form-control'],
        ],
      ],
      'token' => [
        '#type' => 'hidden',
        '#id' => 'token',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function preRenderCompositeFormElement($element) {
    $element = parent::preRenderCompositeFormElement($element);

    return $element;
  }

}
