<?php

namespace Drupal\aaa_cybersource\Plugin\QueueWorker;

use Drupal\aaa_cybersource\Receipts;
use Drupal\aaa_cybersource\CybersourceClient;
use Drupal\aaa_cybersource\Entity\Payment;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
* Receipt queue.
*
* @QueueWorker(
*   id = "receipt_queue",
*   title = @Translation("Receipt Queue."),
*   cron = {"time" = 60}
* )
*/
final class ReceiptQueue extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Receipts handler.
   *
   * @var \Drupal\aaa_cybersource\Receipts
   */
  protected $receiptsHandler;

  /**
   * Cybersource client.
   *
   * @var \Drupal\aaa_cybersource\CybersourceClient
   */
  protected $client;

  /**
   * Main constructor.
   *
   * @param array $configuration
   *   Configuration array.
   * @param mixed $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\aaa_cybersource\Receipts $receipts
   *   Receipts.php.
   * @param \Drupal\aaa_cybersource\CybersourceClient $client
   *   Cybersource client.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, Receipts $receipts, CybersourceClient $client) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->receiptsHandler = $receipts;
    $this->client = $client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('aaa_cybersource.receipts'),
      $container->get('aaa_cybersource.cybersource_client')
    );
  }

  /**
   * Processes an item in the queue.
   *
   * @param mixed $data
   *   The queue item data.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Exception
   */
  public function processItem($data) {
    $this->client->setEnvironment($data->environment);
    $payment = Payment::load($data->pid);
    $key = $data->key;
    $to = $data->to;

    $sent = $this->receiptsHandler->trySendReceipt($this->client, $payment, $key, $to);

    if ($sent === FALSE) {
      throw new \Exception(t('Email was not sent. Payment code @code.', [
        '@code' => $payment->get('code')->value,
      ]));
    }

    return $sent;
  }

}
