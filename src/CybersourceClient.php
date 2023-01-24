<?php

namespace Drupal\aaa_cybersource;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityRepository;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;

use Symfony\Component\HttpFoundation\RequestStack;

use CyberSource\Configuration;
use CyberSource\ApiClient;
use CyberSource\ApiException;
use CyberSource\Authentication\Core\MerchantConfiguration;
use CyberSource\Logging\LogConfiguration;
use CyberSource\Api\InstrumentIdentifierApi;
use CyberSource\Api\KeyGenerationApi;
use CyberSource\Api\PaymentInstrumentApi;
use CyberSource\Api\PaymentsApi;
use CyberSource\Api\TransactionDetailsApi;
use CyberSource\Model\CreatePaymentRequest;
use CyberSource\Model\PostInstrumentIdentifierRequest;
use CyberSource\Model\Ptsv2paymentsClientReferenceInformation;
use CyberSource\Model\Ptsv2paymentsOrderInformation;
use CyberSource\Model\Ptsv2paymentsOrderInformationAmountDetails;
use CyberSource\Model\Ptsv2paymentsOrderInformationBillTo;
use CyberSource\Model\Ptsv2paymentsTokenInformation;
use CyberSource\Model\Tmsv2customersEmbeddedDefaultPaymentInstrumentBillTo;
use CyberSource\Model\Tmsv2customersEmbeddedDefaultPaymentInstrumentCard;
use CyberSource\Model\Tmsv2customersEmbeddedDefaultPaymentInstrumentEmbeddedInstrumentIdentifierCard;
use CyberSource\Model\Tmsv2customersEmbeddedDefaultPaymentInstrumentInstrumentIdentifier;

/**
 * CybersourceClient service creates Cybersource objects and makes requests.
 */
class CybersourceClient {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The logger channel factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $logger;

  /**
   * The logger channel factory.
   *
   * @var \Drupal\Core\Entity\EntityRepository
   */
  protected $entityRepository;


  protected $auth;

  /**
   * @param mixed $auth
   */
  public function setAuth($auth): void
  {
    $this->auth = $auth;
  }

  /**
   * @param string $requestHost
   */
  public function setRequestHost(string $requestHost): void
  {
    $this->requestHost = $requestHost;
  }

  /**
   * @param mixed $merchantId
   */
  public function setMerchantId(mixed $merchantId): void
  {
    $this->merchantId = $merchantId;
  }

  /**
   * @param mixed $merchantKey
   */
  public function setMerchantKey(mixed $merchantKey): void
  {
    $this->merchantKey = $merchantKey;
  }

  /**
   * @param mixed $merchantSecretKey
   */
  public function setMerchantSecretKey(mixed $merchantSecretKey): void
  {
    $this->merchantSecretKey = $merchantSecretKey;
  }

  /**
   * @param mixed $certificateDirectory
   */
  public function setCertificateDirectory($certificateDirectory): void
  {
    $this->certificateDirectory = $certificateDirectory;
  }

  /**
   * @param mixed $certificateFile
   */
  public function setCertificateFile($certificateFile): void
  {
    $this->certificateFile = $certificateFile;
  }

  /**
   * @param mixed $payload
   */
  public function setPayload($payload): void
  {
    $this->payload = $payload;
  }

  /**
   * @param mixed $merchantConfiguration
   */
  public function setMerchantConfiguration($merchantConfiguration): void
  {
    $this->merchantConfiguration = $merchantConfiguration;
  }

  /**
   * @param mixed $settings
   */
  public function setSettings($settings): void
  {
    $this->settings = $settings;
  }

  /**
   * @param mixed $apiClient
   */
  public function setApiClient($apiClient): void
  {
    $this->apiClient = $apiClient;
  }

  protected $requestHost;
  protected $merchantId;
  protected $merchantKey;
  protected $merchantSecretKey;
  protected $certificateDirectory;
  protected $certificateFile;
  protected $payload;
  protected $merchantConfiguration;
  protected $settings;
  protected $apiClient;
  protected $requestStack;
  protected $messenger;

  /**
   * Is the client ready to process transactions?
   *
   * @var bool
   */
  private $ready;

  /**
   * Constructs a CybersourceClient object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   *   The logger channel factory.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger,
    EntityRepository $entity_repository,
    RequestStack $request_stack,
    MessengerInterface $messenger,
  ) {
    $this->configFactory = $config_factory;
    $this->fileSystem = $file_system;
    $this->logger = $logger;
    $this->entityRepository = $entity_repository;
    $this->requestStack = $request_stack;
    $this->messenger = $messenger;

    // Client isn't ready.
    $ready = FALSE;
    $this->setReady($ready);

    $settings = $this->configFactory->get('aaa_cybersource.settings');
    $global = $settings->get('global');

    if (is_null($global) === FALSE) {
      // Initialize with development host until ready.
      $this->setRequestHost('apitest.cybersource.com');

      $this->setAuth($global['auth']);
      $this->setMerchantId($global[$global['environment']]['merchant_id']);
      $this->setMerchantKey($global[$global['environment']]['merchant_key']);
      $this->setMerchantSecretKey($global[$global['environment']]['merchant_secret']);

      if (is_null($global[$global['environment']]['certificate']['fid']) === FALSE) {
        $file = $this->entityRepository->getActive('file', $global[$global['environment']]['certificate']['fid']);

        if (is_null($file) === FALSE) {
          $uri = $file->getFileUri();
          $dir = $this->fileSystem->dirname($uri);
          $realpath = $this->fileSystem->realpath($dir);

          $this->setCertificateDirectory($realpath . DIRECTORY_SEPARATOR);
          $this->setCertificateFile(explode('.', $this->fileSystem->basename($uri))[0]);

          $ready = TRUE;
        }
      }

      $this->setupSettings();
      $this->setupMerchantConfig();

      $api_client = new ApiClient($this->settings, $this->merchantConfiguration);
      $this->setApiClient($api_client);

      // Client is ready.
      $this->setReady($ready);
      $this->setEnvironment($global['environment']);
    }
  }

  public function getClientId() {
    return $this->apiClient->getClientId();
  }

  /**
   * Get a flex token key.
   *
   * @return string
   *   The one-time use flex token.
   */
  public function getFlexToken() {
    if ($this->isReady() === FALSE) {
      return '';
    }

    $instance = new KeyGenerationApi($this->apiClient);
    $request = $this->requestStack->getCurrentRequest();
    $targetOrigin = $request->getSchemeAndHttpHost();

    if (strpos($targetOrigin, 'localhost')) {
      $targetOrigin = 'http://localhost';
    }

    $this->setPayload([
      'encryptionType' => 'RsaOaep256',
      'targetOrigin' => $targetOrigin,
    ]);

    try {
      $keyResponse = $instance->generatePublicKey($this->auth, $this->payload);
      $response = $keyResponse[0];
      return $response->getKeyId();
    }
    catch (ApiException $e) {
      print_r($e->getResponseBody());
      print_r($e->getMessage());

      return '';
    }
  }

  /**
   * Create an instrument identifier token.
   *
   * @param array $data
   *   Instrument (credit card) data.
   *
   * @return array
   *   Response array.
   */
  public function createInstrumentIndentifier(array $data): array {
    $instrumentIdentifierApi = new InstrumentIdentifierApi($this->apiClient);
    $instrumentData = new Tmsv2customersEmbeddedDefaultPaymentInstrumentEmbeddedInstrumentIdentifierCard([
      'number' => $data['card']['number'],
    ]);
    $instrumentIdentifierRequest = new PostInstrumentIdentifierRequest([
      'card' => $instrumentData,
    ]);

    try {
      $response = $instrumentIdentifierApi->postInstrumentIdentifier($instrumentIdentifierRequest);
    }
    catch (ApiException $e) {
      $response['error'] = TRUE;
      $response['message'] = $e->getMessage();
      $response['object'] = $e;
    }
    finally {
      return $response;
    }

    return $response;
  }

  /**
   * Create payment instrument.
   *
   * @param array $data
   *
   * @return array
   */
  public function createPaymentInstrument(array $data): array {
    $paymentInstrumentApi = new PaymentInstrumentApi($this->apiClient);
    $paymentInstrumentCard = new Tmsv2customersEmbeddedDefaultPaymentInstrumentCard($data['card']);
    $paymentInstrumentBillTo = new Tmsv2customersEmbeddedDefaultPaymentInstrumentBillTo($data['billTo']);
    $paymentInstrumentIdentifier = new Tmsv2customersEmbeddedDefaultPaymentInstrumentInstrumentIdentifier($data['instrumentIdentifier']);

    $paymentInstrumentRequest = [
      'instrumentIdentifier' => $paymentInstrumentIdentifier,
      'billTo' => $paymentInstrumentBillTo,
      'card' => $paymentInstrumentCard,
    ];

    try {
      $response = $paymentInstrumentApi->postPaymentInstrumentWithHttpInfo($paymentInstrumentRequest);
    }
    catch (ApiException $e) {
      $response['error'] = TRUE;
      $response['message'] = $e->getMessage();
      $response['object'] = $e;
    }
    finally {
      return $response;
    }

    return $response;
  }

  /**
   * Create a payment token object.
   *
   * @param string $token
   *
   * @return Ptsv2paymentsTokenInformation
   */
  public function createPaymentToken(string $token): Ptsv2paymentsTokenInformation {
    $tokenInformation = new Ptsv2paymentsTokenInformation([
      'transientTokenJwt' => $token,
    ]);

    return $tokenInformation;
  }

  /**
   * Create client reference information object.
   *
   * @param array $data
   *
   * @return Ptsv2paymentsClientReferenceInformation
   */
  public function createClientReferenceInformation(array $data) {
    $clientReferenceInformation = new Ptsv2paymentsClientReferenceInformation($data);

    return $clientReferenceInformation;
  }

  /**
   * Create order amount details.
   *
   * @param array $data
   *
   * @return Ptsv2paymentsOrderInformationAmountDetails
   */
  public function createOrderInformationAmountDetails(array $data) {
    $orderAmountDetails = new Ptsv2paymentsOrderInformationAmountDetails($data);

    return $orderAmountDetails;
  }

  /**
   * Create billing information object.
   *
   * @param array $data
   *
   * @return Ptsv2paymentsOrderInformationBillTo
   */
  public function createBillingInformation(array $data) {
    $orderInformation = new Ptsv2paymentsOrderInformationBillTo($data);

    return $orderInformation;
  }

  /**
   * Create order information object.
   *
   * @param Ptsv2paymentsOrderInformationAmountDetails $amountDetails
   * @param Ptsv2paymentsOrderInformationBillTo        $billTo
   *
   * @return Ptsv2paymentsOrderInformation
   */
  public function createOrderInformation(Ptsv2paymentsOrderInformationAmountDetails $amountDetails, Ptsv2paymentsOrderInformationBillTo $billTo) {
    $orderInformation = new Ptsv2paymentsOrderInformation([
      'amountDetails' => $amountDetails,
      'billTo' => $billTo,
    ]);

    return $orderInformation;
  }

  /**
   * Create a CreatePaymentRequest.
   *
   * @param Ptsv2paymentsClientReferenceInformation $clientReferenceInfo
   * @param Ptsv2paymentsOrderInformation           $orderInformation
   * @param Ptsv2paymentsTokenInformation           $tokenInformation
   *
   * @return CreatePaymentRequest
   */
  public function createPaymentRequest(Ptsv2paymentsClientReferenceInformation $clientReferenceInfo, Ptsv2paymentsOrderInformation $orderInformation, Ptsv2paymentsTokenInformation $tokenInformation) {
    $paymentRequest = new CreatePaymentRequest([
      'clientReferenceInformation' => $clientReferenceInfo,
      'orderInformation' => $orderInformation,
      'tokenInformation' => $tokenInformation,
    ]);

    return $paymentRequest;
  }

  /**
   * Create payment request.
   *
   * @param CreatePaymentRequest $data
   *
   * @return PtsV2PaymentsPost201Response
   */
  public function createPayment(CreatePaymentRequest $req): array {
    $paymentsApi = new PaymentsApi($this->apiClient);

    try {
      $response = $paymentsApi->createPayment($req);
    }
    catch (ApiException $e) {
      $response['error'] = TRUE;
      $response['message'] = $e->getMessage();
      $response['object'] = $e;
    }
    finally {
      return $response;
    }

    return $response;
  }

  /**
   * Return a payment request object.
   *
   * @param string $id
   *   The transaction id.
   *
   * @return TssV2TransactionsGet200Response
   */
  public function getTransaction($id) {
    $transactionDetails = new TransactionDetailsApi($this->apiClient);

    return $transactionDetails->getTransactionWithHttpInfo($id);
  }

  /**
   * Set host using an environment name. Updates merchant configuration.
   *
   * @param string $env
   *   The environment name.
   */
  public function setEnvironment(string $env) {
    if (!isset($this->merchantConfiguration)) {
      return;
    }

    if (strtolower($env) === 'development' || strtolower($env) === 'sandbox') {
      $this->setRequestHost('apitest.cybersource.com');
    }
    elseif (strtolower($env) === 'production') {
      $this->setRequestHost('api.cybersource.com');
    }
    else {
      $this->setRequestHost('apitest.cybersource.com');
    }

    $this->merchantConfiguration->setRunEnvironment($this->requestHost);
  }

  /**
   * Get the current environment name.
   *
   * @return string
   *   Environment name.
   */
  public function getEnvironment() {
    $hosts = [
      'apitest.cybersource.com' => 'development',
      'api.cybersource.com' => 'production',
    ];

    return $hosts[$this->requestHost];
  }

  /**
   * CyberSource client settings.
   */
  private function setupSettings() {
    $settings = new Configuration();
    $logging = new LogConfiguration();
    $logging->setDebugLogFile(__DIR__ . '/' . "debugTest.log");
    $logging->setErrorLogFile(__DIR__ . '/' . "errorTest.log");
    $logging->setLogDateFormat("Y-m-d\TH:i:s");
    $logging->setLogFormat("[%datetime%] [%level_name%] [%channel%] : %message%\n");
    $logging->setLogMaxFiles(3);
    $logging->setLogLevel("debug");
    $logging->enableLogging(TRUE);
    $settings->setLogConfiguration($logging);
    $this->setSettings($settings);
  }

  /**
   * Merchant configuration.
   */
  private function setupMerchantConfig() {
    $merchantConfiguration = new MerchantConfiguration();
    $merchantConfiguration->setAuthenticationType($this->auth);
    $merchantConfiguration->setMerchantID($this->merchantId);
    $merchantConfiguration->setApiKeyID($this->merchantKey);
    $merchantConfiguration->setSecretKey($this->merchantSecretKey);
    $merchantConfiguration->setKeyAlias($this->merchantId);
    $merchantConfiguration->setKeyFileName($this->certificateFile);
    $merchantConfiguration->setKeysDirectory($this->certificateDirectory);
    $merchantConfiguration->setKeyPassword('');
    $merchantConfiguration->setUseMetaKey(FALSE);
    $merchantConfiguration->setRunEnvironment($this->requestHost);

    $this->setMerchantConfiguration($merchantConfiguration);
  }

  /**
   * Set the client ready status.
   *
   * Leave private for now but it may be useful to have other scripts update
   * the client status later.
   *
   * @param boolean $ready
   */
  private function setReady(bool $ready) {
    $this->ready = $ready;
  }

  /**
   * Is the Client ready to make requests.
   *
   * @return boolean
   */
  public function isReady(): bool {
    return $this->ready;
  }

}
