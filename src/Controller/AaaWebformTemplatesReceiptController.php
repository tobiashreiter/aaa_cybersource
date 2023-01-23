<?php

namespace Drupal\aaa_cybersource\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\webform\WebformInterface;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Returns responses for Cybersource Webform Templates routes.
 */
class AaaWebformTemplatesReceiptController extends ControllerBase {
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
  public function receipt(Request $request, WebformInterface $webform = NULL, WebformSubmissionInterface $webform_submission = NULL) {
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
        throw new NotFoundHttpException();
      }
      // Check expiry.
      if (time() > $webform_submission_created + ($receipt_availability * 60 * 60 * 24)) {
        if ($this->currentUser->isAnonymous() === TRUE) {
          throw new NotFoundHttpException();
        }
        // If authenticated user has permissions then allow them to view.
        elseif ($this->currentUser->hasPermission('view aaa_webform_templates receipts') === FALSE) {
          throw new NotFoundHttpException();
        }
      }
    }
    else {
      throw new NotFoundHttpException();
    }

    // Submission Data and Payment entity.
    $data = $webform_submission->getData();
    $payment = $this->entityRepository->getActive('payment', $data['payment_entity']);
    $payment_id = $payment->get('payment_id')->value;
    $transaction = $this->cybersourceClient->getTransaction($payment_id);
    $billTo = $transaction[0]->getOrderInformation()->getBillTo();
    $paymentInformation = $transaction[0]->getPaymentInformation();
    $card = $paymentInformation->getCard();
    $amountDetails = $transaction[0]->getOrderInformation()->getAmountDetails();
    $datetime = $transaction[0]->getSubmitTimeUTC();

    // Build receipt.
    $build['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h1',
      '#value' => $this->t('Receipt'),
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
      '#value' => $this->t('Order Number: :number', [':number' => $payment->get('code')->value]),
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
      '#value' => $this->t('Billing Information'),
    ];

    $build['billing_information']['address'] = [
      '#type' => 'html_tag',
      '#tag' => 'address',
    ];

    $build['billing_information']['address']['name'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t(':firstName :lastName', [
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
        '#value' => $this->t(':value', [':value' => $billTo->getCompany()]),
        '#attributes' => [
          'class' => ['company'],
        ],
      ];
    }

    $build['billing_information']['address']['address'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t(':value', [':value' => $billTo->getAddress1()]),
      '#attributes' => [
        'class' => ['address'],
      ],
    ];

    if (!empty($billTo->getAddress2())) {
      $build['billing_information']['address']['address_2'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $this->t(':value', [':value' => $billTo->getAddress2()]),
        '#attributes' => [
          'class' => ['address-2'],
        ],
      ];
    }

    $build['billing_information']['address']['locality'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t(':value', [':value' => $billTo->getLocality()]),
      '#attributes' => [
        'class' => ['locality'],
      ],
    ];

    $build['billing_information']['address']['area'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t(':value', [':value' => $billTo->getAdministrativeArea()]),
      '#attributes' => [
        'class' => ['area'],
      ],
    ];

    $build['billing_information']['address']['postal_code'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t(':value', [':value' => $billTo->getPostalCode()]),
      '#attributes' => [
        'class' => ['postal-code'],
      ],
    ];

    $build['billing_information']['address']['email'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t(':value', [':value' => $billTo->getEmail()]),
      '#attributes' => [
        'class' => ['email'],
      ],
    ];

    $build['billing_information']['address']['phone'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t(':value', [':value' => $billTo->getPhoneNumber()]),
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
      '#value' => $this->t('Payment Details'),
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
      '#value' => $this->t(':value', [':value' => $this->cardTypeNumberToString($card->getType())]),
    ];

    $build['payment_details']['list']['card_number_term'] = [
      '#type' => 'html_tag',
      '#tag' => 'dd',
      '#value' => 'Card Number',
    ];

    $build['payment_details']['list']['card_number_value'] = [
      '#type' => 'html_tag',
      '#tag' => 'dd',
      '#value' => $this->t('xxxxxxxxxxxx:value', [':value' => $card->getSuffix()]),
    ];

    $build['payment_details']['list']['card_expiration_term'] = [
      '#type' => 'html_tag',
      '#tag' => 'dd',
      '#value' => 'Expiration',
    ];

    $build['payment_details']['list']['card_expiration_value'] = [
      '#type' => 'html_tag',
      '#tag' => 'dd',
      '#value' => $this->t(':month-:year', [':month' => $card->getExpirationMonth(), ':year' => $card->getExpirationYear()]),
    ];

    $build['total'] = [
      '#type' => 'container',
    ];

    $build['total']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Total Amount'),
    ];

    $amount = strpos($amountDetails->getTotalAmount(), '.') > 0 ? $amountDetails->getTotalAmount() : $amountDetails->getTotalAmount() . '.00';
    $build['total']['amount'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t('$:amount', [':amount' => $amount]),
    ];

    return $build;
  }

  private function cardTypeNumberToString($code) {
    $codes = [
      '001' => 'Visa',
      '002' => 'Mastercard',
      '003' => 'American Express',
      '004' => 'Discover',
      '005' => 'Diners Club',
      '006' => 'Carte Blanche',
      '007' => 'JCB',
      '014' => 'Enroute',
      '021' => 'JAL',
      '024' => 'Maestro',
      '031' => 'Delta',
      '033' => 'Visa Electron',
      '034' => 'Dankort',
      '036' => 'Cartes Bancaires',
      '037' => 'Carta Si',
      '039' => 'Encoded account number',
      '040' => 'UATP',
      '042' => 'Maestro',
      '050' => 'Hipercard',
      '051' => 'Aura',
      '054' => 'Elo',
      '062' => 'China UnionPay',
    ];

    return $codes[$code];
  }

}
