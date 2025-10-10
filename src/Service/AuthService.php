<?php

namespace Drupal\ai_provider_quant_cloud\Service;

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
   * Constructs an AuthService.
   */
  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    KeyRepositoryInterface $key_repository
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('ai_provider_quant_cloud');
    $this->keyRepository = $key_repository;
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
   * Get available organizations for the authenticated user.
   *
   * @return array
   *   Array of organizations with 'name' and 'machine_name' keys, or empty array on failure.
   */
  public function getOrganizations(): array {
    $token = $this->getAccessToken();
    
    if (!$token) {
      return [];
    }
    
    try {
      $config = $this->configFactory->get('ai_provider_quant_cloud.settings');
      $platform = $config->get('platform') ?: 'quantcdn';
      
      // Get platform-specific dashboard URL
      $dashboard_url = $config->get("platforms.{$platform}.dashboard_url");
      
      // Fallback to hardcoded URLs if not in config
      if (!$dashboard_url) {
        $dashboard_url = $platform === 'quantgov' 
          ? 'https://dash.quantgov.cloud'
          : 'https://dashboard.quantcdn.io';
      }
      
      // Fetch organizations from dashboard API
      $url = $dashboard_url . '/api/v2/organizations';
      
      $response = $this->httpClient->get($url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Accept' => 'application/json',
        ],
        'timeout' => 10,
        'http_errors' => FALSE,
      ]);
      
      if ($response->getStatusCode() === 200) {
        $body = $response->getBody()->getContents();
        $data = json_decode($body, TRUE);
        
        // API returns array directly: [{"name":"quant","machine_name":"quant"}]
        if (is_array($data) && !empty($data)) {
          $this->logger->info('Successfully fetched @count organizations', [
            '@count' => count($data),
          ]);
          return $data;
        }
      }
      
      $this->logger->warning('Failed to fetch organizations: status @status', [
        '@status' => $response->getStatusCode(),
      ]);
      
      return [];
      
    }
    catch (\Exception $e) {
      $this->logger->error('Error fetching organizations: @message', [
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
   * Get OAuth authorization URL for web flow.
   *
   * This redirects users to the dashboard for OAuth authorization.
   *
   * @param string $state
   *   CSRF state token.
   * @param string $redirect_uri
   *   Callback URI for Drupal.
   *
   * @return string
   *   The authorization URL.
   */
  public function getAuthorizationUrl(string $state, string $redirect_uri): string {
    $config = $this->configFactory->get('ai_provider_quant_cloud.settings');
    $platform = $config->get('platform') ?: 'quantcdn';
    
    // Get platform-specific dashboard URL
    $dashboard_url = $config->get("platforms.{$platform}.dashboard_url");
    
    // Fallback to hardcoded URLs if not in config
    if (!$dashboard_url) {
      $dashboard_url = $platform === 'quantgov' 
        ? 'https://dash.quantgov.cloud'
        : 'https://dashboard.quantcdn.io';
    }
    
    // OAuth authorization endpoint (matches CLI implementation)
    $auth_endpoint = $dashboard_url . '/oauth/authorize';
    
    $params = [
      'client_id' => 'drupal-ai-provider', // Standard client ID for Drupal
      'response_type' => 'code',
      'redirect_uri' => $redirect_uri,
      'state' => $state,
      'scope' => 'ai:read ai:write models:read usage:read',
    ];
    
    return $auth_endpoint . '?' . http_build_query($params);
  }

  /**
   * Exchange authorization code for access token.
   *
   * @param string $code
   *   Authorization code from OAuth callback.
   * @param string $redirect_uri
   *   Callback URI that was used.
   *
   * @return array|null
   *   Token response with access_token, expires_in, etc., or NULL on failure.
   */
  public function exchangeCodeForToken(string $code, string $redirect_uri): ?array {
    $config = $this->configFactory->get('ai_provider_quant_cloud.settings');
    $platform = $config->get('platform') ?: 'quantcdn';
    
    // Get platform-specific dashboard URL
    $dashboard_url = $config->get("platforms.{$platform}.dashboard_url");
    
    // Fallback to hardcoded URLs if not in config
    if (!$dashboard_url) {
      $dashboard_url = $platform === 'quantgov' 
        ? 'https://dash.quantgov.cloud'
        : 'https://dashboard.quantcdn.io';
    }
    
    // OAuth token endpoint (matches CLI implementation)
    $token_endpoint = $dashboard_url . '/oauth/token';
    
    try {
      $response = $this->httpClient->post($token_endpoint, [
        'form_params' => [
          'grant_type' => 'authorization_code',
          'code' => $code,
          'redirect_uri' => $redirect_uri,
          'client_id' => 'drupal-ai-provider',
          // Note: Public client, no client_secret required (like CLI)
        ],
        'timeout' => 30,
      ]);
      
      $body = $response->getBody()->getContents();
      $data = json_decode($body, TRUE);
      
      if (isset($data['access_token'])) {
        $this->logger->info('Successfully exchanged OAuth code for access token');
        return $data;
      }
      
      return NULL;
      
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to exchange OAuth code: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Refresh an expired access token (future implementation).
   *
   * @param string $refresh_token
   *   The refresh token.
   *
   * @return array|null
   *   New token response or NULL on failure.
   */
  public function refreshToken(string $refresh_token): ?array {
    $config = $this->configFactory->get('ai_provider_quant_cloud.settings');
    $platform = $config->get('platform') ?: 'quantcdn';
    
    // Get platform-specific dashboard URL
    $dashboard_url = $config->get("platforms.{$platform}.dashboard_url");
    
    // Future OAuth token endpoint
    $token_endpoint = $dashboard_url . '/oauth/token';
    
    try {
      $response = $this->httpClient->post($token_endpoint, [
        'form_params' => [
          'grant_type' => 'refresh_token',
          'refresh_token' => $refresh_token,
          'client_id' => $config->get('auth.oauth_client_id'),
          'client_secret' => $config->get('auth.oauth_client_secret'),
        ],
        'timeout' => 30,
      ]);
      
      $body = $response->getBody()->getContents();
      $data = json_decode($body, TRUE);
      
      if (isset($data['access_token'])) {
        $this->logger->info('Successfully refreshed access token');
        return $data;
      }
      
      return NULL;
      
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to refresh token: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
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
    // Adjust this path based on your actual dashboard structure
    return $dashboard_url . '/account/api-tokens';
  }

}

