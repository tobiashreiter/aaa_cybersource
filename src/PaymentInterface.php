<?php

namespace Drupal\aaa_cybersource;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityStorageInterface;

/**
 * Provides an interface defining a payment entity type.
 */
interface PaymentInterface extends ContentEntityInterface, EntityChangedInterface {

  /**
   * Gets the payment creation timestamp.
   *
   * @return int
   *   Creation timestamp of the payment.
   */
  public function getCreatedTime();

  /**
   * Sets the payment creation timestamp.
   *
   * @param int $timestamp
   *   The payment creation timestamp.
   *
   * @return \Drupal\aaa_cybersource\PaymentInterface
   *   The called payment entity.
   */
  public function setCreatedTime($timestamp);

  /**
   * Get transaction status value.
   *
   * @return string
   *   A string of the transaction status.
   */
  public function getStatus(): string;

  /**
   * Get recurring status value.
   *
   * @return bool
   */
  public function getRecurring(): bool;

  /**
   * Check if this is a recurring transaction.
   *
   * @return bool
   */
  public function isRecurring(): bool;

  /**
   * Is this an active recurring transaction parent.
   *
   * @return boolean
   */
  public function isActiveRecurring(): bool;

}
