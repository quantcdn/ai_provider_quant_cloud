<?php

namespace Drupal\ai_provider_quant_cloud\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\ai_provider_quant_cloud\Service\AuthService;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * OAuth authentication controller for Quant Cloud.
 */
class OAuthController extends ControllerBase {

  /**
   * The auth service.
   *
   * @var \Drupal\ai_provider_quant_cloud\Service\AuthService
   */
  protected $authService;

  /**
   * The key repository.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_provider_quant_cloud.auth'),
      $container->get('key.repository')
    );
  }

  /**
   * Constructs an OAuthController.
   */
  public function __construct(
    AuthService $auth_service,
    KeyRepositoryInterface $key_repository
  ) {
    $this->authService = $auth_service;
    $this->keyRepository = $key_repository;
  }

  /**
   * Initiates OAuth connection flow.
   */
  public function connect(Request $request) {
    // Generate CSRF state token
    $state = bin2hex(random_bytes(16));
    
    // Store state in session for validation
    $session = $request->getSession();
    $session->set('quant_cloud_oauth_state', $state);
    
    // Build callback URL
    $callback_url = Url::fromRoute('ai_provider_quant_cloud.oauth_callback', [], [
      'absolute' => TRUE,
    ])->toString();
    
    // Get authorization URL
    $auth_url = $this->authService->getAuthorizationUrl($state, $callback_url);
    
    $this->messenger()->addStatus($this->t('Redirecting to Quant Cloud for authorization...'));
    
    // Redirect to dashboard OAuth (use TrustedRedirectResponse for external URLs)
    return new TrustedRedirectResponse($auth_url);
  }

  /**
   * Handles OAuth callback from dashboard.
   */
  public function callback(Request $request) {
    $code = $request->query->get('code');
    $state = $request->query->get('state');
    $error = $request->query->get('error');
    $error_description = $request->query->get('error_description');
    
    // Check for errors
    if ($error) {
      $this->messenger()->addError($this->t('OAuth authorization failed: @error - @description', [
        '@error' => $error,
        '@description' => $error_description ?? 'Unknown error',
      ]));
      
      return $this->redirect('ai_provider_quant_cloud.settings_form');
    }
    
    // Validate state (CSRF protection)
    $session = $request->getSession();
    $stored_state = $session->get('quant_cloud_oauth_state');
    $session->remove('quant_cloud_oauth_state');
    
    if (!$stored_state || $stored_state !== $state) {
      $this->messenger()->addError($this->t('Invalid OAuth state. Possible CSRF attack detected.'));
      return $this->redirect('ai_provider_quant_cloud.settings_form');
    }
    
    if (!$code) {
      $this->messenger()->addError($this->t('No authorization code received from Quant Cloud.'));
      return $this->redirect('ai_provider_quant_cloud.settings_form');
    }
    
    // Exchange code for token
    $callback_url = Url::fromRoute('ai_provider_quant_cloud.oauth_callback', [], [
      'absolute' => TRUE,
    ])->toString();
    
    $token_data = $this->authService->exchangeCodeForToken($code, $callback_url);
    
    if (!$token_data || !isset($token_data['access_token'])) {
      $this->messenger()->addError($this->t('Failed to exchange authorization code for access token.'));
      return $this->redirect('ai_provider_quant_cloud.settings_form');
    }
    
    // Store access token in Key module
    $access_token = $token_data['access_token'];
    $refresh_token = $token_data['refresh_token'] ?? NULL;
    $expires_in = $token_data['expires_in'] ?? 3600;
    
    // Create or update the key for access token
    $key_id = 'quant_cloud_oauth_access_token';
    
    // Check if key exists and delete it (simpler than trying to update)
    $existing_key = $this->keyRepository->getKey($key_id);
    if ($existing_key) {
      $existing_key->delete();
    }
    
    // Create new key with proper configuration
    $key = $this->entityTypeManager()->getStorage('key')->create([
      'id' => $key_id,
      'label' => 'Quant Cloud OAuth Access Token (Auto-generated)',
      'description' => 'OAuth2 access token for Quant Cloud API',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_input' => 'text_field',
      'key_provider_settings' => [
        'key_value' => $access_token,
      ],
    ]);
    $key->save();
    
    // Store refresh token separately if provided
    if ($refresh_token) {
      $refresh_key_id = 'quant_cloud_oauth_refresh_token';
      
      // Delete existing refresh token if it exists
      $existing_refresh = $this->keyRepository->getKey($refresh_key_id);
      if ($existing_refresh) {
        $existing_refresh->delete();
      }
      
      // Create new refresh token key
      $refresh_key = $this->entityTypeManager()->getStorage('key')->create([
        'id' => $refresh_key_id,
        'label' => 'Quant Cloud OAuth Refresh Token (Auto-generated)',
        'description' => 'OAuth2 refresh token for Quant Cloud API',
        'key_type' => 'authentication',
        'key_provider' => 'config',
        'key_input' => 'text_field',
        'key_provider_settings' => [
          'key_value' => $refresh_token,
        ],
      ]);
      $refresh_key->save();
      
      // Store expiry time
      $this->state()->set('quant_cloud_oauth_expires_at', time() + $expires_in);
    }
    
    // Update module configuration to use the OAuth key
    $config = \Drupal::configFactory()->getEditable('ai_provider_quant_cloud.settings');
    $config->set('auth.method', 'oauth');
    $config->set('auth.access_token_key', $key_id);
    $config->save();
    
    $this->messenger()->addStatus($this->t('Successfully connected to Quant Cloud! Your access token has been stored securely.'));
    
    return $this->redirect('ai_provider_quant_cloud.settings_form');
  }

  /**
   * Disconnects OAuth and removes tokens.
   */
  public function disconnect(Request $request) {
    // Remove OAuth keys
    $access_key = $this->keyRepository->getKey('quant_cloud_oauth_access_token');
    if ($access_key) {
      $access_key->delete();
    }
    
    $refresh_key = $this->keyRepository->getKey('quant_cloud_oauth_refresh_token');
    if ($refresh_key) {
      $refresh_key->delete();
    }
    
    // Clear state
    $this->state()->delete('quant_cloud_oauth_expires_at');
    
    // Update config
    $config = \Drupal::configFactory()->getEditable('ai_provider_quant_cloud.settings');
    $config->set('auth.method', 'manual');
    $config->set('auth.access_token_key', NULL);
    $config->save();
    
    $this->messenger()->addStatus($this->t('Disconnected from Quant Cloud. Your tokens have been removed.'));
    
    return $this->redirect('ai_provider_quant_cloud.settings_form');
  }

}

