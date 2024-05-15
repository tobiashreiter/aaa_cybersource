<?php

namespace Drupal\aaa_cybersource;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Database\Connection;
use Drupal\Core\Queue\QueueFactory;
use Drupal\aaa_cybersource\Entity\Payment;
use Drupal\node\Entity\Node;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManager;

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
   * Queue.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queue;

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
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection;
   */
  protected $connection;

  /**
   * EntityTypeManager
   *
   * @var Drupal\Core\Entity\EntityTypeManager;
   */
  protected $entityTypeManager;

  /**
   * Donation Receipt Types
   *
   * @var array;
   */
  protected $receiptTypes = ['',''];

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
  public function __construct(ConfigFactoryInterface $config_factory, DateFormatterInterface $date_formatter, LoggerChannelFactory $logger_factory, Connection $connection, QueueFactory $queue, Mailer $mailer, CybersourceClient $client, EntityTypeManager $entity_type_manager) {
    $this->configFactory = $config_factory;
    $this->dateFormatter = $date_formatter;
    $this->loggerFactory = $logger_factory->get('aaa_cybersource');
    $this->connection = $connection;
    $this->queue = $queue;
    $this->mailer = $mailer;
    $this->cybersourceClient = $client;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Return receipt email subject line.
   *
   * @param Payment $payment
   *
   * @return string
   *   subject line.
   */
  public function getSubject(Payment $payment) {
  	$donationType = $this->getDonationTypeFromPayment($payment);
    return $this->getReceiptSubjectLineByCode($donationType);
  }

  /**
   * Build receipt element.
   *
   * @param Payment $payment
   *   Payment entity.
   * @param array $transaction
   *   Transaction array from Cybersource payment processor.
   *
   * @return array
   *   Build array.
   */
  public function buildReceiptElements(Payment $payment, array $transaction) {
    $billTo = $transaction[0]->getOrderInformation()->getBillTo();
    $paymentInformation = $transaction[0]->getPaymentInformation();
    $card = $paymentInformation->getCard();
    $amountDetails = $transaction[0]->getOrderInformation()->getAmountDetails();
    $datetime = $transaction[0]->getSubmitTimeUTC();
    $donationType = $this->getDonationTypeFromPayment($payment);

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
      '#attributes' => [
        'style' => ['margin-bottom: 25px'],
      ],
    ];

    $build['break_1'] = [
      '#type' => 'html_tag',
      '#tag' => 'hr',
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

    if ($donationType === 'GALA' || !is_null($payment->get('order_details_long')->value)) {
      $build['order_details'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['order-details'],
        ],
      ];

      $build['order_details']['title'] = [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => t('Order Details'),
      ];

      $build['order_details']['content'] = [
        '#type' => 'container',
      ];

      $details = explode('; ', $payment->get('order_details_long')->value);

      foreach ($details as $i => $detail) {
        $build['order_details']['content'][$i] = [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => $detail,
        ];

        if ($i === count($details) - 1) {
          $build['order_details']['content'][$i]['#attributes'] = [
            'style' => ['margin-bottom: 25px'],
          ];
        }
      }
    }

    $build['total'] = [
      '#type' => 'container',
    ];

    $build['total']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => t('Total Amount'),
    ];

    $amount = number_format($amountDetails->getTotalAmount(), 2);
    $build['total']['amount'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => t('$:amount', [':amount' => $amount]),
      '#attributes' => [
        'style' => ['margin-bottom: 25px'],
      ],
    ];

    $build['break_2'] = [
      '#type' => 'html_tag',
      '#tag' => 'hr',
    ];

    $build['message'] = [
      '#type' => 'container',
    ];

    $markup = $this->getReceiptContentsByCode($donationType);

    $build['message']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => t('Thank You'),
    ];

    $build['message']['message'] = [
      '#markup' => $markup,
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
  /**
   * Build an email body. This is the email template.
   *
   * @param Payment $payment
   *   Payment entity.
   * @param  $billTo
   * @param  $paymentInformation
   * @param  $amountDetails
   * @param  $datetime
   *
   * @return string $body
   */
  public function buildReceiptEmailBody(Payment $payment, $billTo, $paymentInformation, $amountDetails, $datetime) {
    $card = $paymentInformation->getCard();
    $amount = number_format($amountDetails->getAuthorizedAmount(), 2);
    $donationType = strpos($payment->get('code')->value, 'GALA') > -1 ? 'GALA' : 'DONATION';

    $body = '';

$body .= "
" . $this->getReceiptContentsByCodeAsText($donationType) . "
";

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

------------------------------------

PAYMENT DETAILS

Card Type {$this->cardTypeNumberToString($card->getType())}
Card Number xxxxxxxxxxxxx{$card->getSuffix()}
Expiration {$card->getExpirationMonth()}-{$card->getExpirationYear()}

------------------------------------
";

    if ($donationType === 'GALA' || !is_null($payment->get('order_details_long')->value)) {
      $details = explode('; ', $payment->get('order_details_long')->value);

$body .= "
ORDER DETAILS
";

      foreach ($details as $detail) {
$body .= "
{$detail}
";
      }
    }

$body .= "
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
  protected function sendReceipt(Payment $payment, $transaction, $key = 'receipt', $to = NULL) {
    $billTo = $transaction[0]->getOrderInformation()->getBillTo();
    $paymentInformation = $transaction[0]->getPaymentInformation();
    $amountDetails = $transaction[0]->getOrderInformation()->getAmountDetails();
    $datetime = $transaction[0]->getSubmitTimeUTC();
    $body = $this->buildReceiptEmailBody($payment, $billTo, $paymentInformation, $amountDetails, $datetime);
    $subject = $this->getSubject($payment);

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
   * Attempt to send a receipt.
   *
   * If the transaction isn't available send a copy of it to the queue.
   *
   * @param CybersourceClient $client
   * @param Payment $payment
   * @param string $key
   * @param string|null $to
   *
   * @return bool
   *   Returns send status.
   */
  public function trySendReceipt(CybersourceClient $client, Payment $payment, $key = 'receipt', $to = NULL) {
    $paymentId = $payment->get('payment_id')->value;
    $transaction = $client->getTransaction($paymentId);

    // If there is an exception, queue this to send next cron.
    if (is_array($transaction) === FALSE && get_class($transaction) === 'CyberSource\ApiException') {
      $environment = $client->getEnvironment();
      $pid = $payment->id();

      if ($this->isPaymentInQueue($pid) !== TRUE) {
        $this->sendToQueue($environment, $pid, $key, $to);
      }

      return FALSE;
    }

    $sent = $this->sendReceipt($payment, $transaction, $key, $to);

    // If sending failed, queue this for later.
    if ($sent !== TRUE) {
      $environment = $client->getEnvironment();
      $pid = $payment->id();

      if ($this->isPaymentInQueue($pid) !== TRUE) {
        $this->sendToQueue($environment, $pid, $key, $to);
      }
    }

    return $sent;
  }

  /**
   * Send receipt email data to the queue.
   *
   * @param string $environment
   * @param int|string $payment_entity_id
   * @param string $to
   * @param string $key
   */
  protected function sendToQueue($environment, $payment_entity_id, $key, $to) {
    $queue = $this->queue->get('receipt_queue');

    $queueItem = new \stdClass();
    $queueItem->environment = $environment;
    $queueItem->pid = (int) $payment_entity_id;
    $queueItem->key = $key;
    $queueItem->to = $to;

    $queue->createItem($queueItem);
  }

  /**
   * Check queue for existing record.
   *
   * @param integer $pid
   *
   * @return bool
   */
  protected function isPaymentInQueue(int $pid) {
    $queued = $this->connection->select('queue', 'q', [])
      ->condition('q.name', 'receipt_queue', '=')
      ->fields('q', ['name', 'data', 'item_id'])
      ->execute();

    foreach ($queued as $item) {
      $data = unserialize($item->data);

      if (is_numeric($data->pid) === TRUE && $pid == $data->pid) {
        return TRUE;
      }
    }

    return FALSE;
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

  /**
   * I return the donation type ('Gala' or 'Donation') based on the payment information
   *
   * @param Payment $payment
   * @return string
   */
  protected function getDonationTypeFromPayment(Payment $payment): string {
  	return strpos($payment->get('code')->value, 'GALA') > -1 ? 'GALA' : 'DONATION';
  }

  /**
   * Returns the supported receipt pages for donations, used for managing receipt notification subjects and notifications
   *
   * @return string[][]
   */
  protected function getReceiptPages() {
  	return [ "GALA" =>
								  			[
								  					'title' => 'Edit Gala Receipt Message (Internal Only)',
								  					'subtitle' => 'Thank you for your support of the Archives of American Art Gala',
								  					'body' => '<p>Thank you for your support of the Archives of American Art Gala.</p>'
								  			],
  					 "DONATION" =>
								  			[
								  					'title' => 'Edit Donation Receipt Message (Internal Only)',
								  					'subtitle' => 'Thank you for your support of the Archives of American Art',
								  					'body' => '<p>Thank you for your support of the Archives of American Art.</p>'
								  			]
  	];

  }
  // Strip HTML tags and decode HTML entities to get text-only version.


  /**
   * I return the subject line for a given receipt based on a given code
   *
   * @param string $code
   * @return string
   */
  protected function getReceiptSubjectLineByCode(string $code): string {
  	return $this->getReceiptFieldValueByCode($code, 'field_subheading');
  }


  /**
   * I return the contents (in text format) for a given receipt based on a given code (by stripping out HTML and decoding any entities
   *
   * @param string $code
   * @return string
   */
  protected function getReceiptContentsByCodeAsText(string $code): string {
  	$receiptContents = $this->getReceiptContentsByCode($code);
  	return strip_tags(Html::decodeEntities($receiptContents));
  }

  /**
   * I return the contents (in HTML) for a given receipt based on a given code
   *
   * @param string $code
   * @return string
   */
  protected function getReceiptContentsByCode(string $code): string {
  	return $this->getReceiptFieldValueByCode($code, 'body');
  }

  /**
   * I return the value of a node field for a given receipt based on a given code
   *
   * @param string $code
   * @return string
   */
  protected function getReceiptFieldValueByCode(string $code, string $field): string {
  	$receiptPageNode = $this->getReceiptPageNodeByCode($code);
  	return $receiptPageNode->get($field)->value;
  }

	/**
	 * I return the node matching a given code among the pages set up for storing receipt messages
	 *
	 * @param string $code
	 * @return Node
	 */
  protected function getReceiptPageNodeByCode(string $code): Node {
  	$receiptPages = $this->getReceiptPages();
  	$title = $receiptPages[$code]['title'];

		return $this->getReceiptPageNodeByTitle($title);
  }

  /**
	 * I return the node matching a given title among the pages set up for storing receipt messages
   *
   * @param string $title
   * @return Node
   */
  protected function getReceiptPageNodeByTitle(string $title): Node {
  	$matchingNodes = $this->entityTypeManager->getStorage('node')->loadByProperties(['title' => $title ]);

  	if (!empty($matchingNodes)) {
  		$receiptPageNode = reset($matchingNodes);
  	}
  	else {
  		// Create a new node.
  		$receiptPageNode = Node::create(['type' => 'page']);
  	}

  	return $receiptPageNode;
  }

  /**
   * I create all of the desired receipt page nodes for managing receipt messages
   *
   */
  public function createReceiptMessagePages(): void {

  	$receiptPages = $this->getReceiptPages();


  	foreach ( $receiptPages as $page => $pageValues ) {
  		$pageNodeTitle = $pageValues['title'];
  		$pageNodeSubTitle = $pageValues['subtitle'];
  		$pageNodeBody = $pageValues['body'];

  		$receiptPageNode = $this->getReceiptPageNodeByTitle($pageNodeTitle);

  		$receiptPageNode->set('title', [
  				'value' => $pageNodeTitle,
  		]);

  		$receiptPageNode->set('field_title_html', [
  				'value' => $pageNodeTitle,
  				'format' => 'basic_html', // Adjust the text format as needed.
  		]);

  		$receiptPageNode->set('field_subheading', [
  				'value' => $pageNodeSubTitle,
  				'format' => 'basic_html', // Adjust the text format as needed.
  		]);

  		$receiptPageNode->set('status', 0);
  		$receiptPageNode->set('body', [
  				'value' => $pageNodeBody,
  				'format' => 'full_html', // Adjust the text format as needed.
  		]);

  		// Load the taxonomy term.
  		$taxonomy_vocabulary = 'Section'; // Replace 'section' with the machine name of your taxonomy vocabulary.
  		$term_name = 'Support'; // Replace 'Support' with the name of the term you want to reference.
  		$terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['vid' => $taxonomy_vocabulary, 'name' => $term_name]);
  		$term = reset($terms);

  		if ($term) {
  			// Set the taxonomy term reference field.
  			$receiptPageNode->set('field_section', $term);
  		}
  		$receiptPageNode->save();

  		$pathAlias = "/" . strtolower($term_name) . "/" . strtolower($page) . '-receipt-message';


  		/*
  		// Check if the path alias already exists.
  		if ($alias_manager->aliasExists($receiptPageNode->toUrl()->getInternalPath(), 'en')) {
  			// If the alias already exists, delete it first.
  			$alias_manager->deleteByPath($receiptPageNode->toUrl()->getInternalPath(), 'en');
  		}
			*/

  		// Get the Path Alias Manager storage.
  		$pathAliasManager = $this->entityTypeManager->getStorage('path_alias');

  		$aliasObjects = $pathAliasManager->loadByProperties([
  				'path'     => '/' . $receiptPageNode->toUrl()->getInternalPath(),
  				'langcode' => 'en'
  		]);

  		foreach($aliasObjects as $aliasObject) {
  			$aliasObject->alias = $pathAlias;
  			$aliasObject->save();
  		}

  	}
  }

}
