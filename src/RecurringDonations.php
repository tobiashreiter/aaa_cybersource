<?php

namespace Drupal\aaa_cybersource;

use Drupal\aaa_cybersource\CybersourceClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityRepository;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class RecurringDonations {
  public function __construct(
    ConfigFactoryInterface $config_factory,
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger,
    EntityRepository $entity_repository,
    RequestStack $request_stack,
    MessengerInterface $messenger,
    CybersourceClient $client
  )
  {
    // Off to the races.
  }

}
