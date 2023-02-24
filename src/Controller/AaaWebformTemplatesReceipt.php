<?php

namespace Drupal\aaa_cybersource\Controller;

use Drupal\aaa_cybersource\Entity\Payment;
use Drupal\Core\Controller\ControllerBase;
use Drupal\webform\WebformInterface;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Returns responses for Cybersource Webform Templates routes.
 */
class AaaWebformTemplatesReceipt extends ControllerBase {
  /**
   * The cybersource client.
   *
   * @var \Drupal\aaa_cybersource\CybersourceClient
   */
  protected $cybersourceClient;

  /**
   * The webform request handler.
   *
   * @var \Drupal\webform\WebformRequestInterface
   */
  protected $requestHandler;

  /**
   * Entity repository.
   *
   * @var EntityRepository
   */
  protected $entityRepository;

  /**
   * Date formatter.
   *
   * @var DateFormatter
   */
  protected $dateFormatter;

  /**
   * Config Factory.
   *
   * @var ConfigFactory
   */
  protected $configFactory;

  /**
   * Current User session.
   *
   * @var AccountProxy
   */
  protected $currentUser;

  /**
   * Logger channel.
   *
   * @var LoggerChannelInterface
   */
  protected $logger;

  protected $receiptHandler;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->cybersourceClient = $container->get('aaa_cybersource.cybersource_client');
    $instance->requestHandler = $container->get('webform.request');
    $instance->entityRepository = $container->get('entity.repository');
    $instance->dateFormatter = $container->get('date.formatter');
    $instance->configFactory = $container->get('config.factory');
    $instance->currentUser = $container->get('current_user');
    $instance->logger = $container->get('logger.factory')->get('aaa_cybersource');
    $instance->receiptHandler = $container->get('aaa_cybersource.receipts');

    return $instance;
  }

  /**
   * Returns a webform receipt page.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param \Drupal\webform\WebformInterface|null $webform
   *   A webform.
   * @param \Drupal\webform\WebformSubmissionInterface|null $webform_submission
   *   A webform submission.
   *
   * @return array
   *   A render array representing a webform confirmation page
   */
  public function webformReceipt(Request $request, WebformInterface $webform = NULL, WebformSubmissionInterface $webform_submission = NULL) {
    /** @var \Drupal\Core\Entity\EntityInterface $source_entity */
    if (!$webform) {
      [$webform, $source_entity] = $this->requestHandler->getWebformEntities();
    }
    else {
      $source_entity = $this->requestHandler->getCurrentSourceEntity('webform');
    }

    // Find the Submission token so that data may be loaded. Otherwise send the
    // user to 404.
    if ($token = $request->get('token')) {
      /** @var \Drupal\webform\WebformSubmissionStorageInterface $webform_submission_storage */
      $webform_submission_storage = $this->entityTypeManager()->getStorage('webform_submission');
      if ($entities = $webform_submission_storage->loadByProperties(['token' => $token])) {
        $webform_submission = reset($entities);
      }

      if (is_null($webform_submission)) {
        $this->logger->debug('Cybersource Receipt Not Found: No webform found.');
        throw new NotFoundHttpException();
      }

      $settings = $this->configFactory->get('aaa_cybersource.settings');
      $receipt_availability = $settings->get('global')['receipt_availibility'];
      $webform_submission_created = $webform_submission->get('created')->value;

      // Allow authenticated users to access.
      if (
        $this->currentUser->isAnonymous() === TRUE
        && time() > $webform_submission_created + ($receipt_availability * 60 * 60 * 24)
      ) {
        $this->logger->debug('Cybersource Receipt Not Found: receipt availability expired.');
        throw new NotFoundHttpException();
      }
      // Check expiry.
      if (time() > $webform_submission_created + ($receipt_availability * 60 * 60 * 24)) {
        if ($this->currentUser->isAnonymous() === TRUE) {
          $this->logger->debug('Cybersource Receipt Not Found: receipt availability expired.');
          throw new NotFoundHttpException();
        }
        // If authenticated user has permissions then allow them to view.
        elseif ($this->currentUser->hasPermission('view aaa_cybersource receipts') === FALSE) {
          $this->logger->debug('Cybersource Receipt Not Found: user lacks permission to view expired receipt.');
          throw new NotFoundHttpException();
        }
      }
    }
    else {
      $this->logger->debug('Cybersource Receipt Not Found: no token found.');
      throw new NotFoundHttpException();
    }

    // Submission Data and Payment entity.
    $data = $webform_submission->getData();
    $payment = $this->entityRepository->getActive('payment', $data['payment_entity']);
    $transaction = $this->getTransactionFromPayment($payment);

    $this->checkTransactionResponse($transaction);

    return $this->receiptHandler->buildReceiptElements($payment, $transaction);
  }

  /**
   * Returns a webform receipt page for admin and privileged users.
   *
   * @param Drupal\aaa_cybersource\Entity\Payment $payment
   *   Payment entity.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array
   *   A render array representing a webform confirmation page
   */
  public function paymentReceipt(Payment $payment = NULL, Request $request) {
    $transaction = $this->getTransactionFromPayment($payment);

    $this->checkTransactionResponse($transaction);

    return $this->receiptHandler->buildReceiptElements($payment, $transaction);
  }

  /**
   * Checks if the object is valid.
   *
   * @throws NotFoundException
   */
  protected function checkTransactionResponse(&$transaction) {
    if (is_array($transaction) === FALSE && get_class($transaction) === 'CyberSource\ApiException') {
      $this->logger->warning('Cybersource API Error.');
      throw new NotFoundHttpException('Error finding transaction');
    }
  }

  /**
   * Get the transaction object.
   *
   * @param Drupal\aaa_cybersource\Entity\Payment $payment
   *   Payment entity.
   *
   * @return array
   */
  protected function getTransactionFromPayment(Payment $payment) {
    $payment_id = $payment->get('payment_id')->value;

    return $this->cybersourceClient->getTransaction($payment_id);
  }

}
