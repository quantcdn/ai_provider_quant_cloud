<?php

namespace Drupal\ai_provider_quant_cloud\Service;

use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * Service for managing authentication with Quant Cloud API.
 */
class AuthService {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The key repository.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The lock backend (used to serialize concurrent refresh attempts).
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected $lock;

  /**
   * Constructs an AuthService.
   */
  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    KeyRepositoryInterface $key_repository,
    EntityTypeManagerInterface $entity_type_manager,
    StateInterface $state,
    LockBackendInterface $lock,
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('ai_provider_quant_cloud');
    $this->keyRepository = $key_repository;
    $this->entityTypeManager = $entity_type_manager;
    $this->state = $state;
    $this->lock = $lock;
  }

  /**
   * Get the access token from Key module.
   *
   * @return string|null
   *   The access token or NULL if not configured.
   */
  public function getAccessToken(): ?string {
    $config = $this->configFactory->get('ai_provider_quant_cloud.settings');
    $key_id = $config->get('auth.access_token_key');

    if (!$key_id) {
      return NULL;
    }

    $key = $this->keyRepository->getKey($key_id);
    return $key ? $key->getKeyValue() : NULL;
  }

  /**
   * Get organizations available to the authenticated user via this OAuth token.
   *
   * Uses the dashboard's /api/oauth/user endpoint (works with any valid OAuth
   * token, including scoped tokens with only ai:use), and returns only the
   * organizations the user explicitly granted during the OAuth approval step.
   * The legacy /api/v2/organizations endpoint requires the projects:read scope
   * and would 403 on a scoped ai:use token.
   *
   * @return array
   *   Array of organizations with 'name' and 'machine_name' keys, or empty
   *   array on failure / no orgs.
   */
  public function getOrganizations(): array {
    $token = $this->getAccessToken();

    if (!$token) {
      return [];
    }

    try {
      $config = $this->configFactory->get('ai_provider_quant_cloud.settings');
      $platform = $config->get('platform') ?: 'quantcdn';
      $dashboard_url = $config->get("platforms.{$platform}.dashboard_url");
      if (!$dashboard_url) {
        $dashboard_url = $platform === 'quantgov'
          ? 'https://dash.quantgov.cloud'
          : 'https://dashboard.quantcdn.io';
      }

      $response = $this->httpClient->get($dashboard_url . '/api/oauth/user', [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Accept' => 'application/json',
        ],
        'timeout' => 10,
        'http_errors' => FALSE,
      ]);

      if ($response->getStatusCode() !== 200) {
        $this->logger->warning('Failed to fetch user/orgs: status @status', [
          '@status' => $response->getStatusCode(),
        ]);
        return [];
      }

      $data = json_decode($response->getBody()->getContents(), TRUE);
      $orgs = $data['organizations'] ?? [];

      // Normalise to the legacy shape ['name' => ..., 'machine_name' => ...]
      // so callers (form dropdown, validateToken) don't care about the source.
      return array_map(static fn(array $org) => [
        'name' => $org['name'] ?? '',
        'machine_name' => $org['machine_name'] ?? '',
      ], array_values($orgs));

    }
    catch (\Exception $e) {
      $this->logger->error('Error fetching user/orgs: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Validate the access token by fetching organizations.
   *
   * @return bool
   *   TRUE if token is valid, FALSE otherwise.
   */
  public function validateToken(): bool {
    $organizations = $this->getOrganizations();
    return !empty($organizations);
  }

  /**
   * Generate a PKCE verifier (RFC 7636).
   *
   * @return string
   *   A base64url-encoded random string (no padding).
   */
  public function generatePkceVerifier(): string {
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
  }

  /**
   * Compute the S256 PKCE challenge from a verifier.
   */
  public function computePkceChallenge(string $verifier): string {
    return rtrim(strtr(base64_encode(hash('sha256', $verifier, TRUE)), '+/', '-_'), '=');
  }

  /**
   * Get OAuth authorization URL for web flow (with PKCE S256).
   *
   * @param string $state
   *   CSRF state token.
   * @param string $redirect_uri
   *   Callback URI for Drupal.
   * @param string $code_challenge
   *   PKCE S256 challenge (base64url, no padding).
   *
   * @return string
   *   The authorization URL.
   */
  public function getAuthorizationUrl(string $state, string $redirect_uri, string $code_challenge): string {
    $config = $this->configFactory->get('ai_provider_quant_cloud.settings');
    $platform = $config->get('platform') ?: 'quantcdn';
    $dashboard_url = $config->get("platforms.{$platform}.dashboard_url");
    if (!$dashboard_url) {
      $dashboard_url = $platform === 'quantgov'
        ? 'https://dash.quantgov.cloud'
        : 'https://dashboard.quantcdn.io';
    }

    $auth_endpoint = $dashboard_url . '/oauth/authorize';

    $params = [
      'client_id' => 'drupal-ai-provider',
      'response_type' => 'code',
      'redirect_uri' => $redirect_uri,
      'state' => $state,
      'scope' => 'ai:use',
      'code_challenge' => $code_challenge,
      'code_challenge_method' => 'S256',
    ];

    return $auth_endpoint . '?' . http_build_query($params);
  }

  /**
   * Exchange authorization code for access + refresh tokens (with PKCE).
   *
   * @return array|null
   *   Token response with access_token, refresh_token, expires_in, etc.,
   *   or NULL on failure.
   */
  public function exchangeCodeForToken(string $code, string $redirect_uri, string $code_verifier): ?array {
    $config = $this->configFactory->get('ai_provider_quant_cloud.settings');
    $platform = $config->get('platform') ?: 'quantcdn';
    $dashboard_url = $config->get("platforms.{$platform}.dashboard_url");
    if (!$dashboard_url) {
      $dashboard_url = $platform === 'quantgov'
        ? 'https://dash.quantgov.cloud'
        : 'https://dashboard.quantcdn.io';
    }

    $token_endpoint = $dashboard_url . '/oauth/token';

    try {
      $response = $this->httpClient->post($token_endpoint, [
        'form_params' => [
          'grant_type' => 'authorization_code',
          'code' => $code,
          'redirect_uri' => $redirect_uri,
          'client_id' => 'drupal-ai-provider',
          'code_verifier' => $code_verifier,
        ],
        'timeout' => 30,
        'http_errors' => FALSE,
      ]);

      if ($response->getStatusCode() !== 200) {
        $this->logger->error('Token exchange failed with status @status: @reason', [
          '@status' => $response->getStatusCode(),
          '@reason' => $response->getReasonPhrase(),
        ]);
        return NULL;
      }

      $data = json_decode($response->getBody()->getContents(), TRUE);
      return isset($data['access_token']) ? $data : NULL;

    }
    catch (\Exception $e) {
      $this->logger->error('Failed to exchange OAuth code: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Persist a token response into Drupal Key + state.
   *
   * Stores access + refresh tokens as Key entities, plus the absolute
   * access-token expiry timestamp in Drupal state. Flips the module to
   * OAuth mode so subsequent calls use the OAuth-issued credentials.
   */
  public function persistTokens(array $token_data): void {
    if (empty($token_data['access_token'])) {
      throw new \InvalidArgumentException('persistTokens called with no access_token');
    }

    $now = time();
    $access_expires_at = isset($token_data['expires_in'])
      ? $now + (int) $token_data['expires_in']
      : NULL;

    $this->writeKey('quant_cloud_oauth_access_token', $token_data['access_token'], 'Quant Cloud — OAuth Access Token');

    if (!empty($token_data['refresh_token'])) {
      $this->writeKey('quant_cloud_oauth_refresh_token', $token_data['refresh_token'], 'Quant Cloud — OAuth Refresh Token');
    }

    if ($access_expires_at !== NULL) {
      $this->state->set('quant_cloud_oauth_expires_at', $access_expires_at);
    }
    else {
      $this->state->delete('quant_cloud_oauth_expires_at');
    }

    $config = $this->configFactory->getEditable('ai_provider_quant_cloud.settings');
    $config->set('auth.method', 'oauth');
    $config->set('auth.access_token_key', 'quant_cloud_oauth_access_token');
    if (!empty($token_data['refresh_token'])) {
      $config->set('auth.refresh_token_key', 'quant_cloud_oauth_refresh_token');
    }
    $config->save();
  }

  /**
   * Create or replace a Key entity holding a token value.
   */
  protected function writeKey(string $key_id, string $value, string $label): void {
    $existing = $this->keyRepository->getKey($key_id);
    if ($existing) {
      $existing->delete();
    }
    $key = $this->entityTypeManager->getStorage('key')->create([
      'id' => $key_id,
      'label' => $label,
      'description' => 'Auto-generated by the Quant Cloud AI provider during OAuth connection. Do not edit manually.',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_input' => 'text_field',
      'key_provider_settings' => [
        'key_value' => $value,
      ],
    ]);
    $key->save();
  }

  /**
   * Get the current refresh token, or NULL if none stored.
   */
  protected function getRefreshToken(): ?string {
    $config = $this->configFactory->get('ai_provider_quant_cloud.settings');
    $key_id = $config->get('auth.refresh_token_key');
    if (!$key_id) {
      return NULL;
    }
    $key = $this->keyRepository->getKey($key_id);
    return $key ? $key->getKeyValue() : NULL;
  }

  /**
   * Exchange a refresh token for a new access + refresh token pair.
   * Uses the public-client form (no client_secret).
   *
   * @param string $refresh_token
   *   The refresh token (qcr_-prefixed plaintext).
   *
   * @return array|null
   *   New token response or NULL on failure.
   */
  public function refreshToken(string $refresh_token): ?array {
    $config = $this->configFactory->get('ai_provider_quant_cloud.settings');
    $platform = $config->get('platform') ?: 'quantcdn';
    $dashboard_url = $config->get("platforms.{$platform}.dashboard_url");
    if (!$dashboard_url) {
      $dashboard_url = $platform === 'quantgov'
        ? 'https://dash.quantgov.cloud'
        : 'https://dashboard.quantcdn.io';
    }

    $token_endpoint = $dashboard_url . '/oauth/token';

    try {
      $response = $this->httpClient->post($token_endpoint, [
        'form_params' => [
          'grant_type' => 'refresh_token',
          'refresh_token' => $refresh_token,
          'client_id' => 'drupal-ai-provider',
        ],
        'timeout' => 30,
        'http_errors' => FALSE,
      ]);

      if ($response->getStatusCode() !== 200) {
        $this->logger->error('Refresh failed with status @status: @reason', [
          '@status' => $response->getStatusCode(),
          '@reason' => $response->getReasonPhrase(),
        ]);
        return NULL;
      }

      $data = json_decode($response->getBody()->getContents(), TRUE);
      return isset($data['access_token']) ? $data : NULL;

    }
    catch (\Exception $e) {
      $this->logger->error('Failed to refresh token: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Return the current access token, refreshing it transparently if expired.
   *
   * Refresh window is "expired or expiring within 60 seconds" so a near-expiry
   * token doesn't get used for a long-running request. Concurrent refresh
   * attempts are serialized through the lock backend; a worker that loses the
   * race waits for the winner and re-reads state, avoiding a double refresh
   * (which the portal would treat as concurrent-refresh / token reuse).
   *
   * Returns NULL if no usable token can be obtained — caller should redirect
   * the user to re-OAuth.
   */
  public function getValidAccessToken(): ?string {
    $access = $this->getAccessToken();
    if (!$access) {
      return NULL;
    }

    if (!$this->isAccessTokenExpiring()) {
      return $access;
    }

    $refresh = $this->getRefreshToken();
    if (!$refresh) {
      $this->logger->warning('Access token expired and no refresh token is stored — re-authentication required.');
      return NULL;
    }

    $lock_id = 'ai_provider_quant_cloud.oauth_refresh';
    if (!$this->lock->acquire($lock_id, 30)) {
      // Another worker is refreshing. Wait for it (best-effort), then re-read.
      $this->lock->wait($lock_id, 30);
      $this->configFactory->reset('ai_provider_quant_cloud.settings');
      $this->state->resetCache();

      if (!$this->isAccessTokenExpiring()) {
        return $this->getAccessToken();
      }
      // Peer didn't refresh successfully — try ourselves.
      $this->lock->acquire($lock_id, 30);
    }

    try {
      $this->configFactory->reset('ai_provider_quant_cloud.settings');
      $this->state->resetCache();
      if (!$this->isAccessTokenExpiring()) {
        return $this->getAccessToken();
      }

      $token_data = $this->refreshToken($refresh);
      if (!$token_data) {
        return NULL;
      }

      $this->persistTokens($token_data);
      return $token_data['access_token'];
    }
    finally {
      $this->lock->release($lock_id);
    }
  }

  /**
   * Whether the stored access token is expired or within 60s of expiring.
   */
  protected function isAccessTokenExpiring(): bool {
    $expires_at = (int) ($this->state->get('quant_cloud_oauth_expires_at') ?? 0);
    return $expires_at > 0 && (time() + 60) >= $expires_at;
  }

  /**
   * Get dashboard URL for manual token generation.
   *
   * @return string
   *   URL where users can generate an access token.
   */
  public function getTokenGenerationUrl(): string {
    $config = $this->configFactory->get('ai_provider_quant_cloud.settings');
    $platform = $config->get('platform') ?: 'quantcdn';

    $dashboard_url = $config->get("platforms.{$platform}.dashboard_url");

    // Assume there's a tokens or API settings page
    // Adjust this path based on your actual dashboard structure.
    return $dashboard_url . '/account/api-tokens';
  }

}
