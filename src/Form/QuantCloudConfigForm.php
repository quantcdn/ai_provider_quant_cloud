<?php

namespace Drupal\ai_provider_quant_cloud\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for Quant Cloud AI Provider.
 */
class QuantCloudConfigForm extends ConfigFormBase {

  /**
   * The key repository.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * The models service.
   *
   * @var \Drupal\ai_provider_quant_cloud\Service\ModelsService
   */
  protected $modelsService;

  /**
   * The auth service.
   *
   * @var \Drupal\ai_provider_quant_cloud\Service\AuthService
   */
  protected $authService;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->keyRepository = $container->get('key.repository');
    $instance->modelsService = $container->get('ai_provider_quant_cloud.models');
    $instance->authService = $container->get('ai_provider_quant_cloud.auth');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ai_provider_quant_cloud.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_provider_quant_cloud_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ai_provider_quant_cloud.settings');

    $form['platform_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Platform Configuration'),
      '#open' => TRUE,
    ];

    $form['platform_section']['platform'] = [
      '#type' => 'radios',
      '#title' => $this->t('Select Platform'),
      '#default_value' => $config->get('platform') ?: 'quantcdn',
      '#options' => [
        'quantcdn' => $this->t('QuantCDN (https://dashboard.quantcdn.io)'),
        'quantgov' => $this->t('QuantGov Cloud (https://dash.quantgov.cloud)'),
      ],
      '#description' => $this->t('Choose which Quant Cloud platform you are using.'),
      '#required' => TRUE,
    ];

    $form['auth_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Authentication'),
      '#open' => TRUE,
    ];

    // Check if already OAuth connected
    $is_oauth_connected = $config->get('auth.method') === 'oauth' && $config->get('auth.access_token_key');
    
    if ($is_oauth_connected) {
      $form['auth_section']['oauth_status'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--status">' . $this->t(
          '✅ <strong>Connected via OAuth:</strong> You are authenticated with Quant Cloud using OAuth2. Your access token will automatically refresh when needed.'
        ) . '</div>',
      ];
      
      $form['auth_section']['oauth_disconnect'] = [
        '#type' => 'link',
        '#title' => $this->t('Disconnect from Quant Cloud'),
        '#url' => \Drupal\Core\Url::fromRoute('ai_provider_quant_cloud.oauth_disconnect'),
        '#attributes' => [
          'class' => ['button', 'button--danger'],
        ],
      ];
    }
    else {
      $form['auth_section']['oauth_connect'] = [
        '#type' => 'markup',
        '#markup' => '<div style="padding: 20px; background: #f5f5f5; border: 2px solid #2196f3; border-radius: 8px; margin: 15px 0;">' .
          '<h3 style="margin-top: 0;">🚀 ' . $this->t('Connect with Quant Cloud (Recommended)') . '</h3>' .
          '<p>' . $this->t('Click the button below to automatically connect your Drupal site to Quant Cloud using secure OAuth2 authentication.') . '</p>' .
          '<p><a href="/admin/config/ai/quant-cloud/oauth/connect" class="button button--primary button--action" style="background: #2196f3; color: white; padding: 12px 24px; font-size: 16px; text-decoration: none; border-radius: 6px; display: inline-block;">' .
          '🔐 ' . $this->t('Connect to Quant Cloud') .
          '</a></p>' .
          '<p style="font-size: 0.9em; color: #666;">' . $this->t('You will be redirected to your Quant Cloud dashboard to authorize this connection.') . '</p>' .
          '</div>',
      ];
    }

    $form['auth_section']['auth_method'] = [
      '#type' => 'radios',
      '#title' => $this->t('Authentication Method'),
      '#default_value' => $config->get('auth.method') ?: 'manual',
      '#options' => [
        'oauth' => $this->t('OAuth2 (Automatic) - One-click connection'),
        'manual' => $this->t('Manual Token - Copy/paste from dashboard'),
      ],
      '#description' => $this->t('OAuth is recommended for automatic token management and refresh.'),
    ];

    // Get available keys
    $key_options = [];
    foreach ($this->keyRepository->getKeys() as $key) {
      $key_options[$key->id()] = $key->label();
    }

    $form['auth_section']['access_token_key'] = [
      '#type' => 'select',
      '#title' => $this->t('Access Token Key'),
      '#default_value' => $config->get('auth.access_token_key'),
      '#options' => $key_options,
      '#empty_option' => $this->t('- Select a key -'),
      '#description' => $this->t('Select the Key module key containing your access token. <a href="@url">Manage keys</a>.', [
        '@url' => '/admin/config/system/keys',
      ]),
      '#states' => [
        'visible' => [
          ':input[name="auth_method"]' => ['value' => 'manual'],
        ],
        'required' => [
          ':input[name="auth_method"]' => ['value' => 'manual'],
        ],
      ],
    ];

    // Fetch available organizations if token is configured
    $org_options = ['' => $this->t('- Select an organization -')];
    $has_orgs = FALSE;
    
    if ($config->get('auth.access_token_key') || $config->get('auth.method') === 'oauth') {
      $organizations = $this->authService->getOrganizations();
      
      if (!empty($organizations)) {
        $has_orgs = TRUE;
        foreach ($organizations as $org) {
          $machine_name = $org['machine_name'] ?? $org['name'] ?? '';
          $org_name = $org['name'] ?? $machine_name;
          if ($machine_name) {
            $org_options[$machine_name] = $machine_name === $org_name 
              ? $org_name 
              : $org_name . ' (' . $machine_name . ')';
          }
        }
      }
    }
    
    $form['auth_section']['organization_id'] = [
      '#type' => $has_orgs ? 'select' : 'textfield',
      '#title' => $this->t('Organization'),
      '#options' => $has_orgs ? $org_options : NULL,
      '#default_value' => $config->get('auth.organization_id'),
      '#description' => $has_orgs 
        ? $this->t('Select your Quant Cloud organization.')
        : $this->t('Your Quant Cloud organization identifier (e.g., "test-org"). Connect via OAuth or configure an access token to see available organizations.'),
      '#required' => TRUE,
    ];

    // Token validation status
    if ($form_state->getValue('access_token_key') || $config->get('auth.access_token_key')) {
      $token_valid = $this->authService->validateToken();
      
      if ($token_valid) {
        $form['auth_section']['token_status'] = [
          '#type' => 'markup',
          '#markup' => '<div class="messages messages--status">' . $this->t(
            '✅ <strong>Token Valid:</strong> Your access token is working correctly and has been validated against the API.'
          ) . '</div>',
          '#weight' => 100,
        ];
      }
      else {
        $form['auth_section']['token_status'] = [
          '#type' => 'markup',
          '#markup' => '<div class="messages messages--error">' . $this->t(
            '❌ <strong>Token Invalid:</strong> Your access token could not be validated. Please check your configuration or generate a new token.'
          ) . '</div>',
          '#weight' => 100,
        ];
      }
    }

    $form['auth_section']['manual_token_help'] = [
      '#type' => 'container',
      '#weight' => 101,
      '#states' => [
        'visible' => [
          ':input[name="auth_method"]' => ['value' => 'manual'],
        ],
      ],
    ];
    
    $form['auth_section']['manual_token_help']['content'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--info">' . $this->t(
        '<strong>How to create a manual token:</strong><ol>
        <li>Log in to your <a href="@quantcdn" target="_blank">QuantCDN</a> or <a href="@quantgov" target="_blank">QuantGov</a> dashboard</li>
        <li>Go to <strong>Profile → Create Token</strong></li>
        <li>Scope the token to the organizations you want to provide access to</li>
        <li>Copy the generated token</li>
        <li>In Drupal, go to <a href="/admin/config/system/keys">Configuration → Keys</a></li>
        <li>Create a new key with the token value</li>
        <li>Return here and select that key above</li>
        </ol>', [
          '@quantcdn' => 'https://dashboard.quantcdn.io',
          '@quantgov' => 'https://dash.quantgov.cloud',
        ]
      ) . '</div>',
    ];

    $form['model_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Model Defaults'),
      '#open' => FALSE,
    ];

    $form['model_section']['default_model'] = [
      '#type' => 'select',
      '#title' => $this->t('Default Model'),
      '#default_value' => $config->get('model.default') ?: 'amazon.nova-lite-v1:0',
      '#options' => $this->getAvailableModels(),
      '#description' => $this->t('The default AI model to use for requests.'),
    ];

    $form['model_section']['temperature'] = [
      '#type' => 'number',
      '#title' => $this->t('Temperature'),
      '#default_value' => $config->get('model.temperature') ?: 0.7,
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
      '#description' => $this->t('Controls randomness. Lower = more focused, Higher = more creative.'),
    ];

    $form['model_section']['max_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Tokens'),
      '#default_value' => $config->get('model.max_tokens') ?: 1000,
      '#min' => 1,
      '#max' => 8192,
      '#description' => $this->t('Maximum number of tokens in the response.'),
    ];

    $form['advanced_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Settings'),
      '#open' => FALSE,
    ];

    $form['advanced_section']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Request Timeout'),
      '#default_value' => $config->get('advanced.timeout') ?: 30,
      '#min' => 5,
      '#max' => 300,
      '#description' => $this->t('HTTP timeout in seconds for regular requests.'),
    ];

    $form['advanced_section']['enable_logging'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Logging'),
      '#default_value' => $config->get('advanced.enable_logging') ?? TRUE,
      '#description' => $this->t('Log API requests and responses for debugging.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('ai_provider_quant_cloud.settings')
      ->set('platform', $form_state->getValue('platform'))
      ->set('auth.method', $form_state->getValue('auth_method'))
      ->set('auth.access_token_key', $form_state->getValue('access_token_key'))
      ->set('auth.organization_id', $form_state->getValue('organization_id'))
      ->set('model.default', $form_state->getValue('default_model'))
      ->set('model.temperature', $form_state->getValue('temperature'))
      ->set('model.max_tokens', $form_state->getValue('max_tokens'))
      ->set('advanced.timeout', $form_state->getValue('timeout'))
      ->set('advanced.enable_logging', $form_state->getValue('enable_logging'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Get available AI models from the API.
   */
  protected function getAvailableModels() {
    try {
      // Fetch chat models from the API (excluding embeddings)
      $models = $this->modelsService->getModels('chat');
      
      $options = [];
      foreach ($models as $model) {
        $model_id = $model['id'] ?? NULL;
        $model_name = $model['name'] ?? $model_id;
        $provider = $model['provider'] ?? '';
        
        if ($model_id) {
          $options[$model_id] = $model_name . ($provider ? " ({$provider})" : '');
        }
      }
      
      // If we got models from the API, return them
      if (!empty($options)) {
        return $options;
      }
      
    }
    catch (\Exception $e) {
      $this->messenger()->addWarning(
        $this->t('Could not fetch models from API. Using fallback list. Error: @error', [
          '@error' => $e->getMessage(),
        ])
      );
    }
    
    // Fallback to minimal list if API is not configured yet or fails
    return [
      'amazon.nova-lite-v1:0' => $this->t('Amazon Nova Lite'),
      'anthropic.claude-3-5-sonnet-20241022-v2:0' => $this->t('Claude 3.5 Sonnet v2'),
    ];
  }

}

