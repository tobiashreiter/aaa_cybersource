<?php

namespace Drupal\aaa_cybersource;

use Drupal\aaa_cybersource\CybersourceClient;
use Drupal\aaa_cybersource\Entity\Payment;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityRepository;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class RecurringPayment {
  protected $storage;
  protected $logger;

  /**
   * CyberSource client.
   *
   * @var \Drupal\aaa_cybersource\CybersourceClient
   */
  protected $cybersourceClient;

  public function __construct(
    ConfigFactoryInterface $config_factory,
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger,
    EntityRepository $entity_repository,
    RequestStack $request_stack,
    MessengerInterface $messenger,
    CybersourceClient $client,
    EntityTypeManager $manager
  ) {
    // Off to the races.
    $this->storage = $manager->getStorage('payment');
    $this->cybersourceClient = $client;
    $this->logger = $logger->get('aaa_cybersource');
  }

  /**
   * Get list of recurring payments.
   *
   * @return array
   *   List of Payments.
   */
  public function query() {
    return $this->storage->getQuery()
      // Recurring only.
      ->condition('recurring', 1)
      // Recurring is active.
      ->condition('recurring_active', 1)
      // Must have payment id.
      ->condition('payment_id', NULL, 'IS NOT NULL')
      // Must have customer stored.
      ->condition('customer_id', NULL, 'IS NOT NULL')
      ->execute();
  }

  /**
   * Build a recurring payment.
   *
   * @param Payment $payment
   * @return bool
   */
  public function buildRecurringPayment(Payment $payment) {
    $payment_id = $payment->get('payment_id')->value;
    $customer_id = $payment->get('customer_id')->value;
    $amount = $payment->get('authorized_amount')->value;
    $currency = $payment->get('currency')->value;
    $environment = $payment->get('environment')->value;
    $code = $payment->get('code')->value;
    $recurring_payments_count = $payment->get('recurring_payments')->count();
    $recurring_payments_max = $payment->get('recurring_max')->value;

    if (($recurring_payments_count + 1) >= $recurring_payments_max) {
      $this->logger->warn('Payment @code recurring charge will not be processed. Number of payments exceeds the maximum value.', [
        '@code' => $code,
      ]);

      return FALSE;
    }

    $this->cybersourceClient->setEnvironment($environment);

    $processingOptions = $this->cybersourceClient->createProcessingOptions($payment_id);

    $newCode = $code . '-' . ($recurring_payments_count + 1);
    $clientReferenceInformation = $this->cybersourceClient->createClientReferenceInformation([
      'code' => $newCode,
    ]);

    $amount = strpos($amount, '.') > 0 ? $amount : $amount . '.00';
    $amountDetails = $this->cybersourceClient->createOrderInformationAmountDetails([
      'totalAmount' => $amount,
      'currency' => $currency,
    ]);

    $orderInformationArr = [
      'amountDetails' => $amountDetails,
    ];

    $orderInformation = $this->cybersourceClient->createOrderInformation($orderInformationArr);

    $customerInformation = $this->cybersourceClient->createPaymentInformationCustomer([
      'customerId' => $customer_id,
    ]);

    $paymentInformation = $this->cybersourceClient->createPaymentInformation([
      'customer' => $customerInformation,
    ]);

    $paymentRequestInfo = [
      'clientReferenceInformation' => $clientReferenceInformation,
      'orderInformation' => $orderInformation,
      'paymentInformation' => $paymentInformation,
      'processingInformation' => $processingOptions,
    ];

    $paymentRequest = $this->cybersourceClient->createPaymentRequest($paymentRequestInfo);

    $payResponse = $this->cybersourceClient->createPayment($paymentRequest);

    // Check for Returned errors.
    if (isset($payResponse['error']) === TRUE && $payResponse['error'] === TRUE) {
      return FALSE;
    }

    // @todo reuse
    $newPaymentId = $payResponse[0]['id'];
    $submitted = $payResponse[0]['submitTimeUtc'];
    $status = $payResponse[0]['status'];
    $isRecurring = FALSE;

    $newPayment = Payment::create([]);
    $newPayment->set('code', $newCode);
    $newPayment->set('payment_id', $newPaymentId);
    $newPayment->set('currency', 'USD');
    $newPayment->set('authorized_amount', $amount);
    $newPayment->set('submitted', $submitted);
    $newPayment->set('status', $status);
    $newPayment->set('recurring', $isRecurring);
    $newPayment->set('environment', $environment);
    $newPayment->set('recurring_active', FALSE);

    if ($isRecurring === TRUE) {
      $tokens = $payResponse[0]->getTokenInformation();
      $customer = $tokens->getCustomer();
      $newPayment->set('customer_id', $customer->getId());
      $newPayment->set('recurring_active', TRUE);
    }

    $newPayment->save();
    $pid = $newPayment->id();

    $allPayments = $payment->get('recurring_payments')->appendItem([
      'target_id' => $pid,
    ]);

    $payment->save();

    return TRUE;
  }

}
