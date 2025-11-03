<?php

namespace Drupal\ai_provider_quant_cloud\Service;

use Drupal\ai_provider_quant_cloud\Client\QuantCloudClient;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for managing AI models from Quant Cloud API.
 */
class ModelsService {

  /**
   * The Quant Cloud client.
   *
   * @var \Drupal\ai_provider_quant_cloud\Client\QuantCloudClient
   */
  protected $client;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Cache lifetime (1 hour).
   */
  const CACHE_LIFETIME = 3600;

  /**
   * Constructs a ModelsService.
   */
  public function __construct(
    QuantCloudClient $client,
    CacheBackendInterface $cache,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->client = $client;
    $this->cache = $cache;
    $this->logger = $logger_factory->get('ai_provider_quant_cloud');
  }

  /**
   * Get all available models from the API.
   *
   * @param string|null $feature
   *   Optional feature filter (chat, embeddings, vision, etc.).
   * @param bool $bypass_cache
   *   Whether to bypass cache and fetch fresh data.
   *
   * @return array
   *   Array of models with their details.
   */
  public function getModels(?string $feature = NULL, bool $bypass_cache = FALSE): array {
    $cache_key = 'ai_provider_quant_cloud:models:' . ($feature ?? 'all');
    
    // Try cache first
    if (!$bypass_cache) {
      $cached = $this->cache->get($cache_key);
      if ($cached && !empty($cached->data)) {
        return $cached->data;
      }
    }
    
    try {
      // Fetch from API
      $filters = [];
      if ($feature) {
        $filters['feature'] = $feature;
      }
      
      $response = $this->client->getModels($filters);
      $models = $response['models'] ?? [];
      
      // Cache the result
      $this->cache->set(
        $cache_key,
        $models,
        time() + self::CACHE_LIFETIME
      );
      
      $this->logger->info('Fetched @count models from Quant Cloud API (feature: @feature)', [
        '@count' => count($models),
        '@feature' => $feature ?? 'all',
      ]);
      
      return $models;
      
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to fetch models from API: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      // Return fallback minimal list
      return $this->getFallbackModels($feature);
    }
  }

  /**
   * Get details for a specific model.
   *
   * @param string $model_id
   *   Model identifier.
   * @param bool $bypass_cache
   *   Whether to bypass cache.
   *
   * @return array|null
   *   Model details or NULL if not found.
   */
  public function getModelDetails(string $model_id, bool $bypass_cache = FALSE): ?array {
    // First, try to get from the cached models list (more efficient)
    $all_models = $this->getModels(NULL, $bypass_cache);
    
    foreach ($all_models as $model) {
      if (isset($model['id']) && $model['id'] === $model_id) {
        return $model;
      }
    }
    
    // If not found in list, return NULL
    // The Dashboard API doesn't have a single-model endpoint yet
    $this->logger->debug('Model @model not found in cached list', [
      '@model' => $model_id,
    ]);
    
    return NULL;
  }

  /**
   * Get models filtered by operation type (for Drupal AI compatibility).
   *
   * @param string $operation_type
   *   Operation type (chat, embeddings, text_to_image).
   *
   * @return array
   *   Associative array of model_id => model_label (simple string).
   */
  public function getModelsForOperation(string $operation_type): array {
    // Map Drupal AI operation types to API features
    $feature_map = [
      'chat' => 'chat',
      'embeddings' => 'embeddings',
      'text_to_image' => 'image_generation',
      'image_to_image' => 'image_generation',
    ];
    
    $feature = $feature_map[$operation_type] ?? NULL;
    $models = $this->getModels($feature);
    
    // Drupal expects simple string labels for form dropdowns
    $result = [];
    foreach ($models as $model) {
      $model_id = $model['id'] ?? NULL;
      if ($model_id) {
        $model_name = $model['name'] ?? $model_id;
        $result[$model_id] = $model_name;
      }
    }
    
    return $result;
  }

  /**
   * Get fallback models when API is unavailable.
   *
   * This is a minimal emergency fallback only.
   *
   * @param string|null $feature
   *   Optional feature filter.
   *
   * @return array
   *   Minimal fallback model list.
   */
  protected function getFallbackModels(?string $feature = NULL): array {
    $all_models = [
      [
        'id' => 'amazon.nova-lite-v1:0',
        'name' => 'Amazon Nova Lite',
        'provider' => 'Amazon',
        'description' => 'Fast and cost-effective model',
        'contextWindow' => 300000,
        'maxOutputTokens' => 5000,
        'supportedFeatures' => ['chat'],
      ],
      [
        'id' => 'anthropic.claude-3-5-sonnet-20241022-v2:0',
        'name' => 'Claude 3.5 Sonnet v2',
        'provider' => 'Anthropic',
        'description' => 'Latest Claude model',
        'contextWindow' => 200000,
        'maxOutputTokens' => 8192,
        'supportedFeatures' => ['chat'],
      ],
      [
        'id' => 'amazon.titan-embed-text-v2:0',
        'name' => 'Titan Text Embeddings v2',
        'provider' => 'Amazon',
        'description' => 'Text embeddings',
        'contextWindow' => 8192,
        'maxOutputTokens' => 0,
        'supportedFeatures' => ['embeddings'],
      ],
      [
        'id' => 'amazon.nova-canvas-v1:0',
        'name' => 'Amazon Nova Canvas',
        'provider' => 'Amazon',
        'description' => 'Text to image generation',
        'contextWindow' => 0,
        'maxOutputTokens' => 0,
        'supportedFeatures' => ['image_generation'],
      ],
    ];
    
    // Filter by feature if specified
    if ($feature) {
      $all_models = array_filter($all_models, function($model) use ($feature) {
        return in_array($feature, $model['supportedFeatures']);
      });
    }
    
    return array_values($all_models);
  }

  /**
   * Clear cached models.
   *
   * Useful after configuration changes or for troubleshooting.
   */
  public function clearCache(): void {
    // Clear all model-related cache entries
    $this->cache->deleteMultiple([
      'ai_provider_quant_cloud:models:all',
      'ai_provider_quant_cloud:models:chat',
      'ai_provider_quant_cloud:models:embeddings',
      'ai_provider_quant_cloud:models:vision',
      'ai_provider_quant_cloud:models:image_generation',
    ]);
    
    $this->logger->info('Cleared Quant Cloud models cache');
  }

}

