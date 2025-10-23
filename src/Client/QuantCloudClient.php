<?php

namespace Drupal\ai_provider_quant_cloud\Client;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * HTTP client for Quant Cloud AI API.
 */
class QuantCloudClient {

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
   * Constructs a QuantCloudClient.
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
   * Get configuration.
   */
  protected function getConfig() {
    return $this->configFactory->get('ai_provider_quant_cloud.settings');
  }

  /**
   * Get access token from Key module.
   */
  protected function getAccessToken(): ?string {
    $config = $this->getConfig();
    $key_id = $config->get('auth.access_token_key');
    
    if (!$key_id) {
      return NULL;
    }
    
    $key = $this->keyRepository->getKey($key_id);
    return $key ? $key->getKeyValue() : NULL;
  }

  /**
   * Get HTTP headers for API requests.
   */
  protected function getHeaders(): array {
    $access_token = $this->getAccessToken();
    
    $headers = [
      'Content-Type' => 'application/json',
      'Accept' => 'application/json',
    ];
    
    if ($access_token) {
      // Dashboard API uses Bearer token authentication
      $headers['Authorization'] = 'Bearer ' . $access_token;
    }
    
    return $headers;
  }

  /**
   * Get Dashboard API base URL.
   */
  protected function getDashboardUrl(): string {
    $config = $this->getConfig();
    $platform = $config->get('platform') ?: 'quantcdn';
    
    // Map platform to dashboard URL
    $urls = [
      'quantcdn' => 'https://dashboard.quantcdn.io',
      'quantgov' => 'https://dash.quantgov.cloud',
      'quantcdn_staging' => 'https://portal.stage.quantcdn.io',
      'quantgov_staging' => 'https://dash.stage.quantgov.cloud',
    ];
    
    $dashboard_url = $urls[$platform] ?? $urls['quantcdn'];
    
    return rtrim($dashboard_url, '/');
  }
  
  /**
   * Get organization ID from config.
   */
  protected function getOrganizationId(): string {
    $config = $this->getConfig();
    $org_id = $config->get('auth.organization_id');
    
    if (!$org_id) {
      throw new \RuntimeException('Organization ID not configured');
    }
    
    return $org_id;
  }
  
  /**
   * Build full API endpoint URL for dashboard API.
   * 
   * @param string $path
   *   API path relative to /api/v3/organisations/{orgId}/ai/
   */
  protected function buildApiUrl(string $path): string {
    $dashboard_url = $this->getDashboardUrl();
    $org_id = $this->getOrganizationId();
    
    // Dashboard API pattern: /api/v3/organisations/{orgId}/ai/{endpoint}
    $base_path = "/api/v3/organisations/{$org_id}/ai";
    $full_path = ltrim($path, '/');
    
    return "{$dashboard_url}{$base_path}/{$full_path}";
  }

  /**
   * Make a POST request to the Dashboard AI API.
   *
   * @param string $path
   *   API path relative to /ai/ (e.g., 'chat', 'chat/stream').
   * @param array $data
   *   Request body data.
   *
   * @return array
   *   Response data.
   *
   * @throws \RuntimeException
   *   If request fails.
   */
  public function post(string $path, array $data): array {
    $config = $this->getConfig();
    $url = $this->buildApiUrl($path);
    
    $options = [
      'headers' => $this->getHeaders(),
      'json' => $data,
      'timeout' => $config->get('advanced.timeout') ?? 30,
    ];
    
    try {
      if ($config->get('advanced.enable_logging')) {
        $this->logger->info('Quant Dashboard AI request: @method @url', [
          '@method' => 'POST',
          '@url' => $url,
        ]);
      }
      
      $response = $this->httpClient->post($url, $options);
      $body = $response->getBody()->getContents();
      $result = json_decode($body, TRUE);
      
      if ($config->get('advanced.enable_logging')) {
        $this->logger->info('Quant Dashboard AI response: @status', [
          '@status' => $response->getStatusCode(),
        ]);
      }
      
      return $result;
      
    }
    catch (GuzzleException $e) {
      $this->logger->error('Quant Dashboard AI request failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new \RuntimeException('AI API request failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Chat completion request (buffered).
   * 
   * Dashboard API route: POST /api/v3/organisations/{orgId}/ai/chat
   */
  public function chat(array $messages, string $model_id, array $options = []): array {
    $config = $this->getConfig();
    
    $data = [
      'messages' => $messages,
      'modelId' => $model_id,
      'temperature' => $options['temperature'] ?? $config->get('model.temperature') ?? 0.7,
      'maxTokens' => $options['maxTokens'] ?? $config->get('model.max_tokens') ?? 1000,
    ];
    
    // Add structured output (JSON Schema) if provided
    if (isset($options['responseFormat'])) {
      $data['responseFormat'] = $options['responseFormat'];
    }
    
    // Add function calling (tools) if provided
    if (isset($options['toolConfig'])) {
      $data['toolConfig'] = $options['toolConfig'];
    }
    
    // Add system prompt if provided
    if (isset($options['systemPrompt'])) {
      $data['systemPrompt'] = $options['systemPrompt'];
    }
    
    return $this->post('chat', $data);
  }

  /**
   * Text completion request.
   * 
   * Note: This uses chat models for text-to-text operations as that's how
   * the Dashboard API is structured. For Drupal AI compatibility.
   */
  public function complete(string $prompt, string $model_id, array $options = []): array {
    $config = $this->getConfig();
    
    // Convert text-to-text to a chat message format
    $data = [
      'messages' => [
        [
          'role' => 'user',
          'content' => $prompt,
        ],
      ],
      'modelId' => $model_id,
      'temperature' => $options['temperature'] ?? $config->get('completion.temperature') ?? 0.3,
      'maxTokens' => $options['maxTokens'] ?? $config->get('model.max_tokens') ?? 500,
    ];
    
    return $this->post('chat', $data);
  }

  /**
   * Embeddings request.
   * 
   * Note: Embeddings may not be available through the dashboard API yet.
   * This is a placeholder for future implementation.
   */
  public function embeddings(array $texts, array $options = []): array {
    $config = $this->getConfig();
    
    $data = [
      'input' => $texts,
      'dimensions' => $options['dimensions'] ?? $config->get('embeddings.dimensions') ?? 1024,
      'normalize' => $options['normalize'] ?? $config->get('embeddings.normalize') ?? TRUE,
    ];
    
    // TODO: Confirm dashboard API endpoint for embeddings
    return $this->post('embeddings', $data);
  }

  /**
   * Make a GET request to the Dashboard AI API.
   *
   * @param string $path
   *   API path relative to /ai/ (e.g., 'models', 'config').
   * @param array $query_params
   *   Query parameters.
   *
   * @return array
   *   Response data.
   *
   * @throws \RuntimeException
   *   If request fails.
   */
  public function get(string $path, array $query_params = []): array {
    $config = $this->getConfig();
    $url = $this->buildApiUrl($path);
    
    // Build URL with query parameters
    if (!empty($query_params)) {
      $query = http_build_query($query_params);
      $url .= '?' . $query;
    }
    
    $options = [
      'headers' => $this->getHeaders(),
      'timeout' => $config->get('advanced.timeout') ?? 30,
    ];
    
    try {
      if ($config->get('advanced.enable_logging')) {
        $this->logger->info('Quant Dashboard AI request: @method @url', [
          '@method' => 'GET',
          '@url' => $url,
        ]);
      }
      
      $response = $this->httpClient->get($url, $options);
      $body = $response->getBody()->getContents();
      $result = json_decode($body, TRUE);
      
      if ($config->get('advanced.enable_logging')) {
        $this->logger->info('Quant Dashboard AI response: @status', [
          '@status' => $response->getStatusCode(),
        ]);
      }
      
      return $result;
      
    }
    catch (GuzzleException $e) {
      $this->logger->error('Quant Dashboard AI request failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new \RuntimeException('AI API request failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Get available models.
   * 
   * Dashboard API route: GET /api/v3/organisations/{orgId}/ai/models
   */
  public function getModels(array $filters = []): array {
    return $this->get('models', $filters);
  }

  /**
   * Get model details.
   * 
   * Dashboard API route: GET /api/v3/organisations/{orgId}/ai/models/{modelId}
   */
  public function getModelDetails(string $model_id): array {
    return $this->get("models/{$model_id}");
  }

}

