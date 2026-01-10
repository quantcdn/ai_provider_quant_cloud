<?php

namespace Drupal\ai_provider_quant_cloud\Client;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * HTTP client for Quant Cloud VectorDB API.
 *
 * Provides methods for managing vector collections and performing
 * semantic search operations via the Quant Cloud Dashboard API.
 */
class QuantCloudVectorDbClient {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The key repository.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected KeyRepositoryInterface $keyRepository;

  /**
   * Constructs a QuantCloudVectorDbClient.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository.
   */
  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    KeyRepositoryInterface $key_repository,
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('ai_provider_quant_cloud');
    $this->keyRepository = $key_repository;
  }

  /**
   * Get configuration.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The module configuration.
   */
  protected function getConfig() {
    return $this->configFactory->get('ai_provider_quant_cloud.settings');
  }

  /**
   * Get access token from Key module.
   *
   * @return string|null
   *   The access token, or NULL if not configured.
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
   *
   * @return array
   *   HTTP headers array.
   */
  protected function getHeaders(): array {
    $access_token = $this->getAccessToken();

    $headers = [
      'Content-Type' => 'application/json',
      'Accept' => 'application/json',
    ];

    if ($access_token) {
      $headers['Authorization'] = 'Bearer ' . $access_token;
    }

    return $headers;
  }

  /**
   * Get Dashboard API base URL.
   *
   * @return string
   *   The dashboard base URL.
   */
  protected function getDashboardUrl(): string {
    $config = $this->getConfig();
    $platform = $config->get('platform') ?: 'quantcdn';

    $urls = [
      'quantcdn' => 'https://dashboard.quantcdn.io',
      'quantgov' => 'https://dash.quantgov.cloud',
      'quantcdn_staging' => 'https://portal.stage.quantcdn.io',
      'quantgov_staging' => 'https://dash.stage.quantgov.cloud',
    ];

    return rtrim($urls[$platform] ?? $urls['quantcdn'], '/');
  }

  /**
   * Get organization ID from config.
   *
   * @return string
   *   The organization ID.
   *
   * @throws \RuntimeException
   *   If organization ID is not configured.
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
   * Build full API endpoint URL for VectorDB API.
   *
   * @param string $path
   *   API path relative to /api/v3/organisations/{orgId}/ai/vector-db/
   *
   * @return string
   *   The full API URL.
   */
  protected function buildApiUrl(string $path): string {
    $dashboard_url = $this->getDashboardUrl();
    $org_id = $this->getOrganizationId();

    $base_path = "/api/v3/organisations/{$org_id}/ai/vector-db";
    $full_path = ltrim($path, '/');

    return "{$dashboard_url}{$base_path}/{$full_path}";
  }

  /**
   * Make a POST request to the VectorDB API.
   *
   * @param string $path
   *   API path relative to /vector-db/
   * @param array $data
   *   Request body data.
   * @param array $request_options
   *   Optional request options (timeout, etc.).
   *
   * @return array
   *   Response data.
   *
   * @throws \RuntimeException
   *   If request fails.
   */
  protected function post(string $path, array $data, array $request_options = []): array {
    $config = $this->getConfig();
    $url = $this->buildApiUrl($path);

    $options = [
      'headers' => $this->getHeaders(),
      'json' => $data,
      'timeout' => $request_options['timeout'] ?? $config->get('advanced.timeout') ?? 30,
      'connect_timeout' => $request_options['connect_timeout'] ?? 10,
    ];

    try {
      if ($config->get('advanced.enable_logging')) {
        $this->logger->info('VectorDB API request: @method @url', [
          '@method' => 'POST',
          '@url' => $url,
        ]);
      }

      $response = $this->httpClient->post($url, $options);
      $body = $response->getBody()->getContents();
      $result = json_decode($body, TRUE);

      if ($config->get('advanced.enable_logging')) {
        $this->logger->info('VectorDB API response: @status', [
          '@status' => $response->getStatusCode(),
        ]);
      }

      return $result ?? [];
    }
    catch (GuzzleException $e) {
      $this->logger->error('VectorDB API request failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new \RuntimeException('VectorDB API request failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Make a GET request to the VectorDB API.
   *
   * @param string $path
   *   API path relative to /vector-db/
   * @param array $query_params
   *   Query parameters.
   *
   * @return array
   *   Response data.
   *
   * @throws \RuntimeException
   *   If request fails.
   */
  protected function get(string $path, array $query_params = []): array {
    $config = $this->getConfig();
    $url = $this->buildApiUrl($path);

    if (!empty($query_params)) {
      $url .= '?' . http_build_query($query_params);
    }

    $options = [
      'headers' => $this->getHeaders(),
      'timeout' => $config->get('advanced.timeout') ?? 30,
    ];

    try {
      if ($config->get('advanced.enable_logging')) {
        $this->logger->info('VectorDB API request: @method @url', [
          '@method' => 'GET',
          '@url' => $url,
        ]);
      }

      $response = $this->httpClient->get($url, $options);
      $body = $response->getBody()->getContents();
      $result = json_decode($body, TRUE);

      if ($config->get('advanced.enable_logging')) {
        $this->logger->info('VectorDB API response: @status', [
          '@status' => $response->getStatusCode(),
        ]);
      }

      return $result ?? [];
    }
    catch (GuzzleException $e) {
      $this->logger->error('VectorDB API request failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new \RuntimeException('VectorDB API request failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Make a DELETE request to the VectorDB API.
   *
   * @param string $path
   *   API path relative to /vector-db/
   *
   * @return array
   *   Response data.
   *
   * @throws \RuntimeException
   *   If request fails.
   */
  protected function delete(string $path): array {
    $config = $this->getConfig();
    $url = $this->buildApiUrl($path);

    $options = [
      'headers' => $this->getHeaders(),
      'timeout' => $config->get('advanced.timeout') ?? 30,
    ];

    try {
      if ($config->get('advanced.enable_logging')) {
        $this->logger->info('VectorDB API request: @method @url', [
          '@method' => 'DELETE',
          '@url' => $url,
        ]);
      }

      $response = $this->httpClient->delete($url, $options);
      $body = $response->getBody()->getContents();
      $result = json_decode($body, TRUE);

      if ($config->get('advanced.enable_logging')) {
        $this->logger->info('VectorDB API response: @status', [
          '@status' => $response->getStatusCode(),
        ]);
      }

      return $result ?? [];
    }
    catch (GuzzleException $e) {
      $this->logger->error('VectorDB API request failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new \RuntimeException('VectorDB API request failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Create a new vector collection.
   *
   * @param string $name
   *   Collection name.
   * @param string|null $description
   *   Optional description.
   * @param string|null $embedding_model
   *   Embedding model ID (defaults to amazon.titan-embed-text-v2:0).
   * @param int|null $dimensions
   *   Vector dimensions (defaults to 1024).
   *
   * @return array
   *   Response containing collection details.
   */
  public function createCollection(string $name, ?string $description = NULL, ?string $embedding_model = NULL, ?int $dimensions = NULL): array {
    $data = ['name' => $name];

    if ($description !== NULL) {
      $data['description'] = $description;
    }
    if ($embedding_model !== NULL) {
      $data['embeddingModel'] = $embedding_model;
    }
    if ($dimensions !== NULL) {
      $data['dimensions'] = $dimensions;
    }

    return $this->post('collections', $data);
  }

  /**
   * List all vector collections.
   *
   * @return array
   *   Response containing collections array and count.
   */
  public function listCollections(): array {
    return $this->get('collections');
  }

  /**
   * Get details of a specific collection.
   *
   * @param string $collection_id
   *   The collection UUID.
   *
   * @return array
   *   Collection details.
   */
  public function getCollection(string $collection_id): array {
    return $this->get("collections/{$collection_id}");
  }

  /**
   * Delete a collection.
   *
   * @param string $collection_id
   *   The collection UUID.
   *
   * @return array
   *   Response with success status.
   */
  public function deleteCollection(string $collection_id): array {
    return $this->delete("collections/{$collection_id}");
  }

  /**
   * Upload documents to a collection.
   *
   * @param string $collection_id
   *   The collection UUID.
   * @param array $documents
   *   Array of documents, each with 'content' and optional 'metadata'.
   *
   * @return array
   *   Response with documentIds and chunksCreated.
   */
  public function uploadDocuments(string $collection_id, array $documents): array {
    return $this->post("collections/{$collection_id}/documents", [
      'documents' => $documents,
    ]);
  }

  /**
   * Query a collection using text search.
   *
   * The server will generate embeddings from the query text.
   *
   * @param string $collection_id
   *   The collection UUID.
   * @param string $query
   *   Natural language search query.
   * @param int $limit
   *   Maximum results (1-20, default 5).
   * @param float $threshold
   *   Minimum similarity score (0-1, default 0.7).
   * @param bool $include_embeddings
   *   Whether to include vectors in response.
   *
   * @return array
   *   Search results with similarity scores.
   */
  public function queryByText(string $collection_id, string $query, int $limit = 5, float $threshold = 0.7, bool $include_embeddings = FALSE): array {
    return $this->post("collections/{$collection_id}/query", [
      'query' => $query,
      'limit' => min(max($limit, 1), 20),
      'threshold' => $threshold,
      'includeEmbeddings' => $include_embeddings,
    ]);
  }

  /**
   * Query a collection using pre-computed vector.
   *
   * Bypasses embedding generation for faster search.
   *
   * @param string $collection_id
   *   The collection UUID.
   * @param array $vector
   *   Pre-computed embedding vector (array of floats).
   * @param int $limit
   *   Maximum results (1-20, default 5).
   * @param float $threshold
   *   Minimum similarity score (0-1, default 0.7).
   * @param bool $include_embeddings
   *   Whether to include vectors in response.
   *
   * @return array
   *   Search results with similarity scores.
   */
  public function queryByVector(string $collection_id, array $vector, int $limit = 5, float $threshold = 0.7, bool $include_embeddings = FALSE): array {
    return $this->post("collections/{$collection_id}/query", [
      'vector' => $vector,
      'limit' => min(max($limit, 1), 20),
      'threshold' => $threshold,
      'includeEmbeddings' => $include_embeddings,
    ]);
  }

}
