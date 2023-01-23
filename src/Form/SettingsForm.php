<?php

namespace Drupal\aaa_cybersource\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Cybersource settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * Entity Type Manager for queries.
   *
   * @var Drupal\Core\Entity\EntityTypeManager
   */
  private $entityTypeManager;

  /**
   * Entity repository loads entities.
   *
   * @var \Drupal\Core\Entity\EntityRepository
   */
  private $entityRepository;

  /**
   * Contains information about all the forms in a keyed array.
   *
   * @var array
   */
  private $forms = [];

  /**
   * Maximum number of days a receipt is available.
   *
   * @var int
   */
  private $receiptAvailibilityMax = 30;

  /**
   * Minimum number of days a receipt is available.
   *
   * @var
   */
  private $receiptAvailibilityMin = 1;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('entity.repository'),
    );
  }

  /**
   * {@inheritDoc}
   */
  public function __construct($config_factory, $entity_type_manager, $entity_repository) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityRepository = $entity_repository;

    $this->forms = [
      // Old AAA Forms.
      // 'donation' => [
      //   'description' => $this->t('AAA Donation Profile - for general donations on the /support page'),
      //   'title' => $this->t('Donation Profile'),
      //   'webform' => FALSE,
      // ],
      // 'tickets' => [
      //   'description' => $this->t('AAA 2020 Gala Donation Profile - this was created to support the 2020/2021 gala which was a donation instead of a ticket purchase.'),
      //   'title' => $this->t('Gala Tickets'),
      //   'webform' => FALSE,
      // ],
      // 'gala' => [
      //   'description' => $this->t('AAA Tickets Profile - for the table of tickets/tables relating to gala'),
      //   'title' => $this->t('Gala Donation (2020/2021)'),
      //   'webform' => FALSE,
      // ],
    ];

    // Include all webforms tagged Cybersource.
    $webform_ids = $this->entityTypeManager->getStorage('webform')->getQuery('AND')->condition('template', TRUE, '<>')->condition('category', 'Cybersource')->execute();
    foreach ($webform_ids as $webform_id) {
      $webform = $this->entityRepository->getActive('webform', $webform_id);
      $this->forms[$webform->get('uuid')] = [
        'description' => $this->t(':description', [':description' => $webform->get('description')]),
        'link' => [
          '#title' => $this->t('Edit Form'),
          '#type' => 'link',
          '#url' => URL::fromRoute('entity.webform.edit_form', ['webform' => 'donation']),
        ],
        'title' => $this->t(':title', [':title' => $webform->label()]),
        'webform' => TRUE,
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'aaa_cybersource_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['aaa_cybersource.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $forms_ids = $this->getFormsIds();
    $config = $this->config('aaa_cybersource.settings');

    $form['#attached']['library'][] = 'aaa_cybersource/settingsForm';

    // Global settings for all forms and fallback.
    $form['global'] = [
      '#type' => 'container',
    ];

    $form['global']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => 'Global settings',
    ];

    $form['global']['fieldset'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Global settings take effect if no value is found in the form settings.'),
    ];

    $form['global']['fieldset']['environment'] = [
      '#type' => 'select',
      '#title' => $this->t('Environment'),
      '#options' => [
        'development' => $this->t('Development'),
        'production' => $this->t('Production'),
      ],
      '#default_value' => $config->get('global')['environment'] ?? 'development',
    ];

    $form['global']['fieldset']['auth'] = [
      '#type' => 'select',
      '#title' => $this->t('Authentication type'),
      '#options' => [
        'HTTP_SIGNATURE' => $this->t('HTTP Signature'),
        'JWT' => $this->t('JWT Certificate'),
      ],
      '#default_value' => $config->get('global')['auth'] ?? '',
    ];

    $form['global']['fieldset']['merchant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Merchant ID'),
      '#default_value' => $config->get('global')['merchant_id'] ?? '',
      '#required' => TRUE,
    ];

    $form['global']['fieldset']['merchant_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Merchant Key'),
      '#default_value' => $config->get('global')['merchant_key'] ?? '',
      '#states' => [
        'required' => [
          ':input[name="auth"]' => ['value' => 'HTTP_SIGNATURE'],
        ],
      ],
    ];

    $form['global']['fieldset']['merchant_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Merchant Shared Secret'),
      '#default_value' => $config->get('global')['merchant_secret'] ?? '',
      '#states' => [
        'required' => [
          ':input[name="auth"]' => ['value' => 'HTTP_SIGNATURE'],
        ],
      ],
    ];

    $fileExists = $config->get('global')['certificate']['fid'] ?? FALSE;
    $form['global']['fieldset']['certificate'] = [
      '#type' => 'managed_file',
      '#upload_location' => 'private://cybersource',
      '#upload_validators' => [
        'file_validate_extensions' => ['pem p12'],
      ],
      '#default_value' => $fileExists === TRUE ? [$config->get('global')['certificate']['fid']] : [],
      '#description' => $fileExists ? $this->t('OK. Certificate previously uploaded.') : $this->t('Warning. No certificate stored'),
      '#title' => $this->t('JWT Certificate'),
      '#states' => [
        'required' => [
          ':input[name="auth"]' => ['value' => 'JWT'],
        ],
      ],
    ];

    $form['global']['fieldset']['receipt_availibility'] = [
      '#type' => 'number',
      '#title' => $this->t('Days of receipt availibility'),
      '#description' => $this->t(
        'Minimum :min. Maximum :max. After this number of days the receipt link shown to the payer will no longer be valid. This is to protect the server from robots and scrapers which could theoretically attempt to generate false tokens to try and scrape data.',
        [
          ':min' => $this->receiptAvailibilityMin,
          ':max' => $this->receiptAvailibilityMax,
        ]
      ),
      '#min' => $this->receiptAvailibilityMin,
      '#max' => $this->receiptAvailibilityMax,
      '#default_value' => $config->get('global')['receipt_availibility'] ?? 7,
    ];

    // Individual forms settings.
    $form['forms'] = [
      '#type' => 'container',
    ];

    $form['forms']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => 'Forms settings',
    ];

    if (count($forms_ids) > 0) {
      $form['forms']['tabs'] = [
        '#type' => 'vertical_tabs',
        '#default_tab' => 'edit-' . $forms_ids[0],
      ];
    }

    $this->buildFormsTabs($form);

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $receipt_availability = $form_state->getValue('receipt_availibility');
    if ($receipt_availability < $this->receiptAvailibilityMin || $receipt_availability > $this->receiptAvailibilityMax) {
      $form_state->setErrorByName('receipt_availibility', $this->t('Invalid number.'));
    }

    $forms = $this->getFormsIds();
    // foreach ($forms as $form_id) {
    //   $env_setting = $form_state->getValue($form_id . '_environment', '');

    //   if (!empty($env_setting)) {
    //     $profile = $form_state->getValue($form_id . '_' . $env_setting . '_profile', '');
    //     if (empty($profile)) {
    //       $form_state->setErrorByName($form_id . '_' . $env_setting . '_profile', $this->t('Profile ID must be set in order to use this environment.'));
    //     }

    //     $access_key = $form_state->getValue($form_id . '_' . $env_setting . '_access_key', '');
    //     if (empty($access_key)) {
    //       $form_state->setErrorByName($form_id . '_' . $env_setting . '_access_key', $this->t('Access Key must be set in order to use this environment.'));
    //     }

    //     $secret_key = $form_state->getValue($form_id . '_' . $env_setting . '_secret_key', '');
    //     if (empty($secret_key)) {
    //       $form_state->setErrorByName($form_id . '_' . $env_setting . '_secret_key', $this->t('Secret Key must be set in order to use this environment.'));
    //     }
    //   }
    // }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('aaa_cybersource.settings');
    $forms = $this->getFormsIds();
    $environments = $this->getEnvironments();

    foreach ($forms as $form_id) {
      foreach ($environments as $environment) {
        $config->set($form_id . '_' . $environment . '_profile', $form_state->getValue($form_id . '_' . $environment . '_profile', ''));
        $config->set($form_id . '_' . $environment . '_access_key', $form_state->getValue($form_id . '_' . $environment . '_access_key', ''));
        $config->set($form_id . '_' . $environment . '_secret_key', $form_state->getValue($form_id . '_' . $environment . '_secret_key', ''));
      }

      $config->set($form_id . '_environment', $form_state->getValue($form_id . '_environment', ''));

      if ($this->forms[$form_id]['webform'] === TRUE) {
        $config->set($form_id . '_code', $form_state->getValue($form_id . '_code', 'AAA'));
      }
    }

    $global = $config->get('global');
    $formFile = $form_state->getValue('certificate', 0);
    if (is_array($formFile) && isset($formFile[0])) {
      $file = File::load($formFile[0]);
      $file->setPermanent();
      $file->save();
    }
    elseif (is_null($global['certificate']['fid']) === FALSE) {
      $file = File::load($global['certificate']['fid']);
    }

    $config->set('global', [
      'auth' => $form_state->getValue('auth', $global['auth'] ?? ''),
      'environment' => $form_state->getValue('environment', $global['environment'] ?? ''),
      'merchant_id' => $form_state->getValue('merchant_id', $global['merchant_id'] ?? ''),
      'merchant_key' => $form_state->getValue('merchant_key', $global['merchant_key'] ?? ''),
      'merchant_secret' => $form_state->getValue('merchant_secret', $global['merchant_secret'] ?? ''),
      'certificate' => [
        'fid' => isset($file) ? $file->id() : NULL,
      ],
      'receipt_availibility' => $form_state->getValue('receipt_availibility', $global['receipt_availibility'] ?? 7),
    ]);

    $config->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * The Cybersource environments.
   *
   * @return array
   */
  private function getEnvironments() {
    return ['production', 'development'];
  }

  /**
   * Keys which refer to Cybersource forms on the site.
   *
   * @return array
   */
  private function getFormsIds() {
    return array_keys($this->forms);
  }

  /**
   * Titles of the Cybersource forms.
   *
   * @param string $key
   *
   * @return string
   */
  private function getFormTitle($key) {
    return $this->forms[$key]['title'];
  }

  /**
   * Helpful descriptions of the Cybersource forms.
   *
   * @param string $key
   *
   * @return string
   */
  private function getFormDescription($key) {
    return $this->forms[$key]['description'];
  }

  /**
   * Build the elements for each form.
   *
   * @param array &$form
   *   The form array.
   */
  private function buildFormsTabs(array &$form) {
    $forms = $this->getFormsIds();
    $environments = $this->getEnvironments();

    if (count($forms) === 0) {
      $form['forms']['tabs'] = [
        '#type' => 'item',
        '#markup' => 'No Cybersource forms found.',
      ];
    }

    foreach ($forms as $form_id) {
      $form['forms']['tabs'][$form_id] = [
        '#type' => 'details',
        '#title' => $this->getFormTitle($form_id),
        '#group' => 'forms',
      ];

      $form['forms']['tabs'][$form_id]['description'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->getFormDescription($form_id),
      ];

      if ($this->formHasLink($form_id)) {
        $form['forms']['tabs'][$form_id]['link'] = $this->getFormLink($form_id);
      }

      // foreach ($environments as $environment) {
      //   $form['forms']['tabs'][$form_id][$environment] = [
      //     '#type' => 'fieldset',
      //     '#title' => $this->t(':environment Environment', [':environment' => ucwords($environment)]),
      //   ];

      //   $key = $form_id . '_' . $environment . '_profile';
      //   $form['forms']['tabs'][$form_id][$environment][$key] = [
      //     '#type' => 'textfield',
      //     '#title' => 'Profile ID',
      //     '#default_value' => $this->config('aaa_cybersource.settings')->get($key) ?? '',
      //   ];

      //   $key = $form_id . '_' . $environment . '_access_key';
      //   $form['forms']['tabs'][$form_id][$environment][$key] = [
      //     '#type' => 'textfield',
      //     '#title' => 'Access Key',
      //     '#default_value' => $this->config('aaa_cybersource.settings')->get($key) ?? '',
      //   ];

      //   $key = $form_id . '_' . $environment . '_secret_key';
      //   $form['forms']['tabs'][$form_id][$environment][$key] = [
      //     '#type' => 'textfield',
      //     '#title' => 'Secret Key',
      //     '#maxlength' => 256,
      //     '#default_value' => $this->config('aaa_cybersource.settings')->get($key) ?? '',
      //   ];

      // }

      if ($this->forms[$form_id]['webform'] === TRUE) {
        $key = $form_id . '_code';
        $form['forms']['tabs'][$form_id][$key] = [
          '#title' => $this->t('Code prefix'),
          '#type' => 'textfield',
          '#description' => $this->t('The site generates its own unique code for each transaction. By default this is "AAA" but if you prefer to vary it by the type of form you may change it in this setting.'),
          '#default_value' => $this->config('aaa_cybersource.settings')->get($key) ?? '',
          '#placeholder' => $this->t('AAA'),
          '#maxlength' => 16,
          '#attributes' => [
            'class' => ['form-element--type-text--uppercase'],
            'style' => ['text-transform: uppercase;'],
          ],
        ];
      }

      $key = $form_id . '_environment';
      $form['forms']['tabs'][$form_id][$key] = [
        '#type' => 'select',
        '#title' => $this->t('Select the environment.'),
        '#description' => $this->t('This setting switches the form environment where ever it is rendered sitewide. Use Development for testing purposes only.'),
        '#default_value' => $this->config('aaa_cybersource.settings')->get($key) ?? '',
        '#empty_value' => '',
        '#empty_option' => ' - Not set - ',
        '#options' => [
          'production' => $this->t('Production'),
          'development' => $this->t('Development'),
        ],
      ];
    }

  }

  /**
   * Get the link element.
   *
   * @param string $form_id
   *
   * @return array
   */
  private function getFormLink($form_id) {
    return $this->forms[$form_id]['link'];
  }

  /**
   * Check if link exists.
   *
   * @param string $form_id
   *
   * @return bool
   */
  private function formHasLink($form_id) {
    return isset($this->forms[$form_id]['link']);
  }

}
