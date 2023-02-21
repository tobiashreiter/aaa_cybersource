<?php

namespace Drupal\aaa_cybersource;

use Drupal\aaa_cybersource\Entity\Payment;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Recurring Payment manager.
 */
class RecurringPayment {
  /**
   * Storage interface for the Payment entity.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $storage;

  /**
   * Logger interface.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $logger;

  /**
   * CyberSource client.
   *
   * @var \Drupal\aaa_cybersource\CybersourceClient
   */
  protected $cybersourceClient;

  /**
   * Mailer.
   *
   * @var \Drupal\aaa_cybersource\Mailer
   */
  protected $mailer;

  /**
   * Receipt hander.
   *
   * @var \Drupal\aaa_cybersource\Receipts
   */
  protected $receiptHandler;

  /**
   * Database datetime format.
   *
   * @var string
   */
  protected $dateTimeFormat = 'Y-m-d\TH:i:s';

  /**
   * Construct this manager.
   *
   * @param Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config factory interface.
   * @param Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   *   Drupal logger channel interface.
   * @param Drupal\Core\Entity\EntityTypeManager $manager
   *   Entitytype manager.
   * @param Drupal\aaa_cybersource\CybersourceClient $client
   *   AAA CyberSource clients.
   * @param Drupal\aaa_cybersource\Mailer $mailer
   *   Mailer manager.
   * @param Drupal\aaa_cybersource\Receipts $receipts
   *   Receipt handler.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger,
    EntityTypeManager $manager,
    CybersourceClient $client,
    Mailer $mailer,
    Receipts $receipts,
  ) {
    // Off to the races.
    $this->storage = $manager->getStorage('payment');
    $this->cybersourceClient = $client;
    $this->logger = $logger->get('aaa_cybersource');
    $this->mailer = $mailer;
    $this->receiptHandler = $receipts;
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
      // Stored recurring_next date must be passed.
      ->condition('recurring_next', date($this->dateTimeFormat), '<')
      ->execute();
  }

  /**
   * Build a recurring payment.
   *
   * @param Drupal\aaa_cybersource\Entity\Payment $payment
   *   Payment entity.
   *
   * @return bool
   *   TRUE if the transaction was made successfully.
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
      $this->logger->info('Payment @code recurring charge will not be processed. Number of payments exceeds the maximum value.', [
        '@code' => $code,
      ]);

      // Disable recurring active. Maximum number met or exceeded.
      $payment->set('recurring_active', FALSE);
      $payment->save();

      return FALSE;
    }

    // Set up the payment request.
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

    // Save the Payment entity.
    $newPaymentId = $payResponse[0]['id'];
    $submitted = $payResponse[0]['submitTimeUtc'];
    $status = $payResponse[0]['status'];
    // Not recurring.
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

    // Update "parent" recurring payment.
    $allPayments = $payment->get('recurring_payments')->appendItem([
      'target_id' => $pid,
    ]);

    // Check if max number of payments is exceeded after this transaction.
    if (($payment->get('recurring_payments')->count() + 1) < $recurring_payments_max) {
      // Set the next recurring payment date time.
      $lastRecurringPayment = $newPayment->get('created')->value;
      $nextRecurringTime = $this->getNextRecurringTime($lastRecurringPayment);
      $format = 'Y-m-d\TH:i:s';
      $nextRecurringDateTimeFormat = date($this->dateTimeFormat, $nextRecurringTime);
      $payment->set('recurring_next', $nextRecurringDateTimeFormat);
    }
    else {
      // Disable active recurring flag when the number of recurring payments meets the maximum.
      $payment->set('recurring_active', FALSE);
    }

    $payment->save();

    // Give platform time to process.
    sleep(5);

    // Create and send receipt.
    $transaction = $this->cybersourceClient->getTransaction($newPaymentId);
    $key = $payment->id() . '_recurring_payment_handler_cron';
    $subject = $this->receiptHandler->getSubject();
    $transaction = $this->cybersourceClient->getTransaction($payment_id);
    $billTo = $transaction[0]->getOrderInformation()->getBillTo();
    $to = $billTo->getEmail();
    $paymentInformation = $transaction[0]->getPaymentInformation();
    $amountDetails = $transaction[0]->getOrderInformation()->getAmountDetails();
    $datetime = $transaction[0]->getSubmitTimeUTC();

    $body = $this->receiptHandler->buildReceiptEmailBody($newPayment, $billTo, $paymentInformation, $amountDetails, $datetime);
    $result = $this->mailer->sendMail($key, $to, $subject, $body);

    if ($result['send'] === TRUE) {
      $context = [
        '@code' => $payment->get('code')->value,
        'link' => $payment->toLink('View', 'canonical')->toString(),
      ];

      $this->logger->info('Recurring donation cron sent receipt email for @code recurring transaction.', $context);
    }

    return TRUE;
  }

  /**
   * Create the next recurring time. +1 month.
   *
   * @param int $timestamp
   *   Current timestamp.
   *
   * @return int
   *   the next recurring timestamp.
   */
  protected function getNextRecurringTime(int $timestamp) {
    return strtotime('+1 month', $timestamp);
  }

}
