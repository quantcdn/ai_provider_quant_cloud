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
   * Initiates OAuth connection flow with PKCE.
   */
  public function connect(Request $request) {
    $state = bin2hex(random_bytes(16));
    $verifier = $this->authService->generatePkceVerifier();
    $challenge = $this->authService->computePkceChallenge($verifier);

    $session = $request->getSession();
    $session->set('quant_cloud_oauth_state', $state);
    $session->set('quant_cloud_oauth_pkce_verifier', $verifier);

    $callback_url = Url::fromRoute('ai_provider_quant_cloud.oauth_callback', [], [
      'absolute' => TRUE,
    ])->toString();

    $auth_url = $this->authService->getAuthorizationUrl($state, $callback_url, $challenge);

    $this->messenger()->addStatus($this->t('Redirecting to Quant Cloud for authorization...'));

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

    if ($error) {
      $this->messenger()->addError($this->t('OAuth authorization failed: @error - @description', [
        '@error' => $error,
        '@description' => $error_description ?? 'Unknown error',
      ]));
      return $this->redirect('ai_provider_quant_cloud.settings_form');
    }

    $session = $request->getSession();
    $stored_state = $session->get('quant_cloud_oauth_state');
    $verifier = $session->get('quant_cloud_oauth_pkce_verifier');
    $session->remove('quant_cloud_oauth_state');
    $session->remove('quant_cloud_oauth_pkce_verifier');

    if (!$stored_state || $stored_state !== $state) {
      $this->messenger()->addError($this->t('Invalid OAuth state. Possible CSRF attack detected.'));
      return $this->redirect('ai_provider_quant_cloud.settings_form');
    }

    if (!$code || !$verifier) {
      $this->messenger()->addError($this->t('OAuth flow corrupted: missing code or PKCE verifier.'));
      return $this->redirect('ai_provider_quant_cloud.settings_form');
    }

    $callback_url = Url::fromRoute('ai_provider_quant_cloud.oauth_callback', [], [
      'absolute' => TRUE,
    ])->toString();

    $token_data = $this->authService->exchangeCodeForToken($code, $callback_url, $verifier);

    if (!$token_data || !isset($token_data['access_token'])) {
      $this->messenger()->addError($this->t('Failed to exchange authorization code for access token.'));
      return $this->redirect('ai_provider_quant_cloud.settings_form');
    }

    $this->authService->persistTokens($token_data);

    $this->messenger()->addStatus($this->t('Successfully connected to Quant Cloud! Your access token has been stored securely.'));

    return $this->redirect('ai_provider_quant_cloud.settings_form');
  }

  /**
   * Disconnects OAuth and removes tokens.
   */
  public function disconnect(Request $request) {
    foreach (['quant_cloud_oauth_access_token', 'quant_cloud_oauth_refresh_token'] as $key_id) {
      $key = $this->keyRepository->getKey($key_id);
      if ($key) {
        $key->delete();
      }
    }

    $this->state()->delete('quant_cloud_oauth_expires_at');

    $config = \Drupal::configFactory()->getEditable('ai_provider_quant_cloud.settings');
    $config->set('auth.method', 'manual');
    $config->set('auth.access_token_key', NULL);
    $config->set('auth.refresh_token_key', NULL);
    $config->save();

    $this->messenger()->addStatus($this->t('Disconnected from Quant Cloud. Your tokens have been removed.'));

    return $this->redirect('ai_provider_quant_cloud.settings_form');
  }

}

