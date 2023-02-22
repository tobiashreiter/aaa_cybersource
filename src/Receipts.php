<?php

namespace Drupal\aaa_cybersource;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\aaa_cybersource\Entity\Payment;

/**
 * Defines a receipts service with helpful methods to build receipts.
 *
 * @package Drupal\aaa_cybersource
 */
class Receipts {

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Logger Factory Interface.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactory
   */
  protected $loggerFactory;

  /**
   * Mailer.
   *
   * @var \Drupal\aaa_cybersource\Mailer
   */
  protected $mailer;

  /**
   * The cybersource client.
   *
   * @var \Drupal\aaa_cybersource\CybersourceClient
   */
  protected $cybersourceClient;

  /**
   * Constructs a new Receipt object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   Date formatter functions.
   * @param \Drupal\Core\Logger\LoggerChannelFactory $logger_factory
   *   Logging in drupal.
   */
  public function __construct(ConfigFactoryInterface $config_factory, DateFormatterInterface $date_formatter, LoggerChannelFactory $logger_factory, Mailer $mailer, CybersourceClient $client) {
    $this->configFactory = $config_factory;
    $this->dateFormatter = $date_formatter;
    $this->loggerFactory = $logger_factory->get('aaa_cybersource');
    $this->mailer = $mailer;
    $this->cybersourceClient = $client;
  }

  /**
   * Return receipt email subject line.
   *
   * @return string
   *   subject line.
   */
  public function getSubject() {
    return 'Your Receipt';
  }

  /**
   * Build receipt element.
   *
   * @param Drupal\aaa_cybersource\Entity\Payment $payment
   *   Payment entity.
   * @param array $transaction
   *   Transaction array from Cybersource payment processor.
   *
   * @return array
   *   Build array.
   */
  public function buildReceiptElements(Payment $payment, array $transaction) {
    $payment_id = $payment->get('payment_id')->value;
    $billTo = $transaction[0]->getOrderInformation()->getBillTo();
    $paymentInformation = $transaction[0]->getPaymentInformation();
    $card = $paymentInformation->getCard();
    $amountDetails = $transaction[0]->getOrderInformation()->getAmountDetails();
    $datetime = $transaction[0]->getSubmitTimeUTC();

    // Build receipt.
    $build['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h1',
      '#value' => t('Receipt'),
    ];

    $build['meta'] = [
      '#type' => 'container',
    ];

    $build['meta']['date'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => 'Date: ' . $this->dateFormatter->format(strtotime($datetime), 'long'),
    ];

    $build['meta']['order_number'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => t('Order Number: :number', [':number' => $payment->get('code')->value]),
    ];

    $build['billing_information'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['billing-information'],
      ],
    ];

    $build['billing_information']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => t('Billing Information'),
    ];

    $build['billing_information']['address'] = [
      '#type' => 'html_tag',
      '#tag' => 'address',
    ];

    $build['billing_information']['address']['name'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => t(':firstName :lastName', [
        ':firstName' => $billTo->getFirstName(),
        ':lastName' => $billTo->getLastName(),
      ]),
      '#attributes' => [
        'class' => ['name'],
      ],
    ];

    if (!empty($billTo->getCompany())) {
      $build['billing_information']['address']['company'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => t(':value', [':value' => $billTo->getCompany()]),
        '#attributes' => [
          'class' => ['company'],
        ],
      ];
    }

    $build['billing_information']['address']['address'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => t(':value', [':value' => $billTo->getAddress1()]),
      '#attributes' => [
        'class' => ['address'],
      ],
    ];

    if (!empty($billTo->getAddress2())) {
      $build['billing_information']['address']['address_2'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => t(':value', [':value' => $billTo->getAddress2()]),
        '#attributes' => [
          'class' => ['address-2'],
        ],
      ];
    }

    $build['billing_information']['address']['locality'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => t(':value', [':value' => $billTo->getLocality()]),
      '#attributes' => [
        'class' => ['locality'],
      ],
    ];

    $build['billing_information']['address']['area'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => t(':value', [':value' => $billTo->getAdministrativeArea()]),
      '#attributes' => [
        'class' => ['area'],
      ],
    ];

    $build['billing_information']['address']['postal_code'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => t(':value', [':value' => $billTo->getPostalCode()]),
      '#attributes' => [
        'class' => ['postal-code'],
      ],
    ];

    $build['billing_information']['address']['email'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => t(':value', [':value' => $billTo->getEmail()]),
      '#attributes' => [
        'class' => ['email'],
      ],
    ];

    $build['billing_information']['address']['phone'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => t(':value', [':value' => $billTo->getPhoneNumber()]),
      '#attributes' => [
        'class' => ['phone'],
      ],
    ];

    $build['payment_details'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['billing-information'],
      ],
    ];

    $build['payment_details']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => t('Payment Details'),
    ];

    $build['payment_details']['list'] = [
      '#type' => 'html_tag',
      '#tag' => 'dl',
    ];

    $build['payment_details']['list']['card_type_term'] = [
      '#type' => 'html_tag',
      '#tag' => 'dd',
      '#value' => 'Card Type',
    ];

    $build['payment_details']['list']['card_type_value'] = [
      '#type' => 'html_tag',
      '#tag' => 'dd',
      '#value' => t(':value', [':value' => aaa_cybersource_card_type_number_to_string($card->getType())]),
    ];

    $build['payment_details']['list']['card_number_term'] = [
      '#type' => 'html_tag',
      '#tag' => 'dd',
      '#value' => 'Card Number',
    ];

    $build['payment_details']['list']['card_number_value'] = [
      '#type' => 'html_tag',
      '#tag' => 'dd',
      '#value' => t('xxxxxxxxxxxx:value', [':value' => $card->getSuffix()]),
    ];

    $build['payment_details']['list']['card_expiration_term'] = [
      '#type' => 'html_tag',
      '#tag' => 'dd',
      '#value' => 'Expiration',
    ];

    $build['payment_details']['list']['card_expiration_value'] = [
      '#type' => 'html_tag',
      '#tag' => 'dd',
      '#value' => t(':month-:year', [
        ':month' => $card->getExpirationMonth(),
        ':year' => $card->getExpirationYear(),
      ]),
    ];

    $build['total'] = [
      '#type' => 'container',
    ];

    $build['total']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => t('Total Amount'),
    ];

    $amount = strpos($amountDetails->getTotalAmount(), '.') > 0 ? $amountDetails->getTotalAmount() : $amountDetails->getTotalAmount() . '.00';
    $build['total']['amount'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => t('$:amount', [':amount' => $amount]),
    ];

    return $build;
  }

  /**
   * Build receipt body for email.
   *
   * @param Drupal\aaa_cybersource\Entity\Payment $payment
   *   Payment entity.
   *
   * @return string
   *   Body text of the receipt.
   */
  public function buildReceiptEmailBody(Payment $payment, $billTo, $paymentInformation, $amountDetails, $datetime) {
    $card = $paymentInformation->getCard();
    $amount = strpos($amountDetails->getAuthorizedAmount(), '.') > 0 ? $amountDetails->getAuthorizedAmount() : $amountDetails->getAuthorizedAmount() . '.00';

    $body = '';

    $body .= "
      RECEIPT

      Date: {$this->dateFormatter->format(strtotime($datetime), 'long')}
      Order Number: {$payment->get('code')->value}

      ------------------------------------

      BILLING INFORMATION

      {$billTo->getFirstName()} {$billTo->getLastName()}";

    if (!empty($billTo->getCompany())) {
      $body .= "
      {$billTo->getCompany()}";
    }

    $body .= "
      {$billTo->getAddress1()}";

    if (!empty($billTo->getAddress2())) {
      $body .= "
      {$billTo->getAddress2()}";
    }

    $body .= "
      {$billTo->getLocality()}
      {$billTo->getAdministrativeArea()}
      {$billTo->getPostalCode()}
      {$billTo->getEmail()}
      {$billTo->getPhoneNumber()}

      PAYMENT DETAILS
      Card Type {$this->cardTypeNumberToString($card->getType())}
      Card Number xxxxxxxxxxxxx{$card->getSuffix()}
      Expiration {$card->getExpirationMonth()}-{$card->getExpirationYear()}

      TOTAL AMOUNT
      $ {$amount}
      ";

    return $body;
  }

  /**
   * Given payment information and the receipient, send a receipt.
   *
   * @param CybersourceClient $client
   * @param string            $key
   * @param string            $to
   * @param Payment           $payment
   *
   * @return bool
   */
  public function sendReceipt(CybersourceClient $client, Payment $payment, $key = 'receipt', $to = NULL) {
    $payment_id = $payment->get('payment_id')->value;
    $transaction = $client->getTransaction($payment_id);

    // @todo this is where receipt should be queued.
    if (is_array($transaction) === FALSE && get_class($transaction) === 'CyberSource\ApiException') {
      return FALSE;
    }

    $billTo = $transaction[0]->getOrderInformation()->getBillTo();
    $paymentInformation = $transaction[0]->getPaymentInformation();
    $amountDetails = $transaction[0]->getOrderInformation()->getAmountDetails();
    $datetime = $transaction[0]->getSubmitTimeUTC();
    $body = $this->buildReceiptEmailBody($payment, $billTo, $paymentInformation, $amountDetails, $datetime);
    $subject = $this->getSubject();

    if (is_null($to) === TRUE) {
      $to = $billTo->getEmail();
    }

    $result = $this->mailer->sendMail($key, $to, $subject, $body);

    if ($result['send'] === TRUE) {
      $context = [
        '@code' => $payment->get('code')->value,
        'link' => $payment->toLink('View', 'canonical')->toString(),
      ];
      $this->loggerFactory->info('Payment code @code receipt emailed.', $context);
    }

    return $result['send'];
  }

  /**
   * Takes code and returns human readable name.
   *
   * @param string $code
   *   The card type symbol.
   *
   * @return string
   *   human readable card type.
   */
  protected function cardTypeNumberToString($code) {
    return aaa_cybersource_card_type_number_to_string($code);
  }

}
