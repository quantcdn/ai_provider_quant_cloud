<?php

namespace Drupal\ai_provider_quant_cloud\Plugin\AiProvider;

use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInterface;
use Drupal\ai\OperationType\Embeddings\EmbeddingsOutput;
use Drupal\ai_provider_quant_cloud\Client\QuantCloudClient;
use Drupal\ai_provider_quant_cloud\Client\QuantCloudStreamingClient;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Quant Cloud AI Provider plugin.
 */
#[AiProvider(
  id: 'quant_cloud',
  label: new TranslatableMarkup('Quant Cloud AI'),
)]
class QuantCloudProvider extends AiProviderClientBase implements 
  ContainerFactoryPluginInterface,
  ChatInterface,
  EmbeddingsInterface {

  /**
   * The Quant Cloud client.
   *
   * @var \Drupal\ai_provider_quant_cloud\Client\QuantCloudClient
   */
  protected $client;

  /**
   * The Quant Cloud streaming client.
   *
   * @var \Drupal\ai_provider_quant_cloud\Client\QuantCloudStreamingClient
   */
  protected $streamingClient;

  /**
   * The models service.
   *
   * @var \Drupal\ai_provider_quant_cloud\Service\ModelsService
   */
  protected $modelsService;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->client = $container->get('ai_provider_quant_cloud.client');
    $instance->streamingClient = $container->get('ai_provider_quant_cloud.streaming_client');
    $instance->modelsService = $container->get('ai_provider_quant_cloud.models');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfig(): ImmutableConfig {
    return $this->configFactory->get('ai_provider_quant_cloud.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function getApiDefinition(): array {
    $definition = Yaml::parseFile(
      $this->moduleHandler->getModule('ai_provider_quant_cloud')
        ->getPath() . '/definitions/api_defaults.yml'
    );
    return $definition;
  }

  /**
   * {@inheritdoc}
   */
  public function isUsable(?string $operation_type = NULL, $capabilities = []): bool {
    $config = $this->getConfig();
    
    // Check basic configuration: token and organization ID are required
    if (!$config->get('auth.access_token_key') || !$config->get('auth.organization_id')) {
      return FALSE;
    }
    
    // Check platform is configured
    if (!$config->get('platform')) {
      return FALSE;
    }
    
    // If operation type is specified, check if we support it
    if ($operation_type) {
      return in_array($operation_type, $this->getSupportedOperationTypes());
    }
    
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    return [
      'chat',
      'embeddings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguredModels(?string $operation_type = NULL, $capabilities = []): array {
    // Fetch models dynamically from the API via ModelsService
    if ($operation_type) {
      return $this->modelsService->getModelsForOperation($operation_type);
    }
    
    // Return all models if no operation type specified
    try {
      $api_models = $this->modelsService->getModels();
      
      $models = [];
      foreach ($api_models as $model) {
        $model_id = $model['id'] ?? NULL;
        if (!$model_id) {
          continue;
        }
        
        // Drupal expects simple string labels for form dropdowns
        $model_name = $model['name'] ?? $model_id;
        $models[$model_id] = $model_name;
      }
      
      return $models;
      
    }
    catch (\Exception $e) {
      // Return empty array if API is not configured or fails
      // The provider won't be usable until configuration is complete
      return [];
    }
  }

  /**
   * Map API features to Drupal AI operation types.
   *
   * @param array $features
   *   API supported features.
   *
   * @return array
   *   Drupal AI operation types.
   */
  protected function mapFeaturesToOperations(array $features): array {
    $operations = [];
    
    if (in_array('chat', $features)) {
      $operations[] = 'chat';
    }
    
    if (in_array('embeddings', $features)) {
      $operations[] = 'embeddings';
    }
    
    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function chat(ChatInput|array|string $input, string $model_id, array $tags = []): ChatOutput {
    // Normalize input to ChatInput
    if (is_string($input)) {
      $input = new ChatInput([new ChatMessage('user', $input)]);
    }
    elseif (is_array($input)) {
      $messages = [];
      foreach ($input as $msg) {
        if (is_array($msg)) {
          $messages[] = new ChatMessage($msg['role'] ?? 'user', $msg['content'] ?? '');
        }
      }
      $input = new ChatInput($messages);
    }
    
    // Format messages
    $messages = $this->formatMessages($input->getMessages());
    
    try {
      // Check if streaming is requested via tags
      $use_streaming = in_array('streaming', $tags) || in_array('stream', $tags);
      
      if ($use_streaming) {
        // Streaming via SSE - collect all chunks
        $full_content = '';
        $this->streamingClient->chatStream(
          $messages,
          $model_id,
          function($delta, $is_complete) use (&$full_content) {
            $full_content .= $delta;
          }
        );
        
        $response_data = [
          'role' => 'assistant',
          'content' => $full_content,
        ];
      }
      else {
        // Buffered (default) - best for forms and batch processing
        $result = $this->client->chat($messages, $model_id);
        $response_data = $result['response'] ?? [];
      }
      
      $content = $response_data['content'] ?? '';
      $role = $response_data['role'] ?? 'assistant';
      
      // Create ChatMessage for the response
      $message = new ChatMessage($role, $content);
      
      return new ChatOutput($message, $result, NULL);
      
    }
    catch (\Exception $e) {
      throw new \RuntimeException('Chat request failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function embeddings(string|EmbeddingsInput $input, string $model_id, array $tags = []): EmbeddingsOutput {
    // Normalize input to EmbeddingsInput
    if (is_string($input)) {
      $input = new EmbeddingsInput([$input]);
    }
    
    try {
      $result = $this->client->embeddings($input->getTexts());
      
      $embeddings = [];
      foreach ($result['embeddings'] ?? [] as $embedding_data) {
        $embeddings[] = $embedding_data['embedding'];
      }
      
      return new EmbeddingsOutput($embeddings, NULL, []);
      
    }
    catch (\Exception $e) {
      throw new \RuntimeException('Embeddings request failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setAuthentication(mixed $authentication): void {
    // Store authentication for later use by the client
    // The authentication is passed to the HTTP client via headers
    $this->configuration['authentication'] = $authentication;
  }

  /**
   * {@inheritdoc}
   */
  public function getModelSettings(string $model_id, array $generalConfig = []): array {
    // Return model-specific configuration
    // For now, return the general config as-is
    // Future: fetch model-specific limits from the API
    return $generalConfig;
  }

  /**
   * {@inheritdoc}
   */
  public function maxEmbeddingsInput(string $model_id = ''): int {
    // Maximum number of texts that can be embedded in a single request
    // This is a reasonable default for most models
    return 96;
  }

  /**
   * {@inheritdoc}
   */
  public function getMaxInputTokens(string $model_id): int {
    // Try to get from API first
    try {
      $model_details = $this->modelsService->getModelDetails($model_id);
      if ($model_details && isset($model_details['contextWindow'])) {
        return (int) $model_details['contextWindow'];
      }
    }
    catch (\Exception $e) {
      // Fall through to defaults
    }

    // Fallback to hardcoded limits if API unavailable
    $limits = [
      'amazon.nova-lite-v1:0' => 300000,
      'amazon.nova-pro-v1:0' => 300000,
      'amazon.nova-micro-v1:0' => 128000,
      'anthropic.claude-3-5-sonnet-20241022-v2:0' => 200000,
      'anthropic.claude-3-5-sonnet-20240620-v1:0' => 200000,
      'anthropic.claude-3-opus-20240229-v1:0' => 200000,
      'anthropic.claude-3-sonnet-20240229-v1:0' => 200000,
      'anthropic.claude-3-haiku-20240307-v1:0' => 200000,
      'amazon.titan-embed-text-v2:0' => 8192,
      'amazon.titan-embed-text-v1' => 8192,
    ];

    return $limits[$model_id] ?? 100000;
  }

  /**
   * {@inheritdoc}
   */
  public function getMaxOutputTokens(string $model_id): int {
    // Try to get from API first
    try {
      $model_details = $this->modelsService->getModelDetails($model_id);
      if ($model_details && isset($model_details['maxOutputTokens'])) {
        return (int) $model_details['maxOutputTokens'];
      }
    }
    catch (\Exception $e) {
      // Fall through to defaults
    }

    // Fallback to hardcoded limits if API unavailable
    $limits = [
      'amazon.nova-lite-v1:0' => 5000,
      'amazon.nova-pro-v1:0' => 5000,
      'amazon.nova-micro-v1:0' => 5000,
      'anthropic.claude-3-5-sonnet-20241022-v2:0' => 8192,
      'anthropic.claude-3-5-sonnet-20240620-v1:0' => 8192,
      'anthropic.claude-3-opus-20240229-v1:0' => 4096,
      'anthropic.claude-3-sonnet-20240229-v1:0' => 4096,
      'anthropic.claude-3-haiku-20240307-v1:0' => 4096,
      'amazon.titan-embed-text-v2:0' => 0,
      'amazon.titan-embed-text-v1' => 0,
    ];

    return $limits[$model_id] ?? 4096;
  }

  /**
   * Format chat messages for API.
   */
  protected function formatMessages(array $messages): array {
    $formatted = [];
    
    foreach ($messages as $message) {
      if ($message instanceof ChatMessage) {
        $formatted[] = [
          'role' => $message->getRole(),
          'content' => $message->getText(),
        ];
      }
    }
    
    return $formatted;
  }

}

