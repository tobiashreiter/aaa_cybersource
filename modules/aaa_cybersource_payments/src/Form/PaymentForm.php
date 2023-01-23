<?php

namespace Drupal\aaa_cybersource_payments\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form controller for the payment entity edit forms.
 */
class PaymentForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {

    $entity = $this->getEntity();
    $result = $entity->save();
    $link = $entity->toLink($this->t('View'))->toRenderable();

    $message_arguments = ['%label' => $this->entity->label()];
    $logger_arguments = $message_arguments + ['link' => render($link)];

    if ($result == SAVED_NEW) {
      $this->messenger()->addStatus($this->t('New payment %label has been created.', $message_arguments));
      $this->logger('aaa_cybersource_payments')->notice('Created new payment %label', $logger_arguments);
    }
    else {
      $this->messenger()->addStatus($this->t('The payment %label has been updated.', $message_arguments));
      $this->logger('aaa_cybersource_payments')->notice('Updated new payment %label.', $logger_arguments);
    }

    $form_state->setRedirect('entity.payment.canonical', ['payment' => $entity->id()]);
  }

}
