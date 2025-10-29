<?php

namespace Drupal\ai_provider_quant_cloud\Plugin\AiProvider;

use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInterface;
use Drupal\ai\OperationType\Embeddings\EmbeddingsOutput;
use Drupal\ai_provider_quant_cloud\Client\QuantCloudClient;
use Drupal\ai_provider_quant_cloud\Client\QuantCloudStreamingClient;
use Drupal\ai_provider_quant_cloud\QuantCloudChatMessageIterator;
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
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->client = $container->get('ai_provider_quant_cloud.client');
    $instance->streamingClient = $container->get('ai_provider_quant_cloud.streaming_client');
    $instance->modelsService = $container->get('ai_provider_quant_cloud.models');
    $instance->logger = $container->get('logger.factory')->get('ai_provider_quant_cloud');
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
    try {
      // Log what's being requested (convert enums to strings)
      if (!empty($capabilities)) {
        $cap_strings = array_map(function($cap) {
          return $cap instanceof \Drupal\ai\Enum\AiModelCapability ? $cap->value : (string)$cap;
        }, $capabilities);
        $this->logger->info('🔍 Getting models with capabilities: @caps', [
          '@caps' => implode(', ', $cap_strings),
        ]);
      }
      
      // Get models for the operation type (defaults to 'chat' if not specified)
      $feature = $operation_type ?: 'chat';
      $api_models = $this->modelsService->getModels($feature);
      
      $models = [];
      foreach ($api_models as $model) {
        $model_id = $model['id'] ?? NULL;
        if (!$model_id) {
          continue;
        }
        
        // Filter by capabilities if specified
        if (!empty($capabilities)) {
          $model_capabilities = $model['capabilities'] ?? [];
          
          // Check if model supports all required capabilities
          $supports_all = TRUE;
          foreach ($capabilities as $capability) {
            // Convert enum to string value if needed
            $capability_string = $capability instanceof \Drupal\ai\Enum\AiModelCapability ? $capability->value : (string)$capability;
            
            // Map Drupal capability names to our API capability flags
            $capability_flag = $this->mapCapabilityFlag($capability_string);
            
            if ($capability_flag && empty($model_capabilities[$capability_flag])) {
              $supports_all = FALSE;
              break;
            }
          }
          
          // Skip this model if it doesn't support required capabilities
          if (!$supports_all) {
            continue;
          }
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
   * Map Drupal capability names to API capability flags.
   *
   * @param string $capability
   *   Drupal capability name (from AiModelCapability enum).
   *
   * @return string|null
   *   API capability flag, or NULL if not mapped.
   */
  protected function mapCapabilityFlag(string $capability): ?string {
    $mapping = [
      // Function calling / Tools
      'chat_tools' => 'supportsTools',
      'chat_combined_tools_and_structured_response' => 'supportsTools',
      
      // Structured output / JSON
      'chat_json_output' => 'supportsStructuredOutput',
      'chat_structured_response' => 'supportsStructuredOutput',
      
      // Vision / Multimodal
      'chat_with_image_vision' => 'supportsVision',
      'chat_with_video' => 'supportsMultimodal',
      'chat_with_audio' => 'supportsMultimodal',
    ];
    
    return $mapping[$capability] ?? NULL;
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
    
    // Format messages (supports multimodal content)
    $messages = $this->formatMessages($input->getMessages());
    
    // Build request options
    $options = [];
    
    // Check for structured output (JSON Schema)
    if ($input->getChatStructuredJsonSchema()) {
      $schema = $input->getChatStructuredJsonSchema();
      $options['responseFormat'] = [
        'type' => 'json',
        'jsonSchema' => $schema,
      ];
    }
    
    // Check for tools/function calling
    if ($input->getChatTools()) {
      $tools_input = $input->getChatTools();
      $options['toolConfig'] = [
        'tools' => $this->formatToolsForApi($tools_input),
      ];
    }
    
    // Check for system prompt
    if (method_exists($input, 'getSystemRole') && $input->getSystemRole()) {
      $options['systemPrompt'] = $input->getSystemRole();
    }
    
      try {
        // Check if streaming is requested (set by base class from UI checkbox)
        $use_streaming = $this->streamed ?? FALSE;
        
        if ($use_streaming) {
          // Streaming via SSE - return iterator for real-time streaming
          $stream = $this->streamingClient->chatStreamRaw($messages, $model_id, $options);
          
          // Create streaming iterator (like AWS Bedrock provider does)
          $message = QuantCloudChatMessageIterator::create($stream, $this->logger);
          
          // Return ChatOutput with the iterator as the message
          // The iterator will be consumed by AI Explorer for real-time display
          return new ChatOutput($message, [], NULL);
        }
        else {
          // Buffered (default) - best for forms and batch processing
          $response_data = $this->client->chat($messages, $model_id, $options);
        
        // Handle Lambda response format:
        // { "response": { "content": "...", "role": "assistant", "toolUse": {...} }, "usage": {...} }
        if (isset($response_data['response'])) {
          // Standard nested format
          $message_data = $response_data['response'];
          $content = $message_data['content'] ?? '';
          $role = $message_data['role'] ?? 'assistant';
          $tool_use_data = $message_data['toolUse'] ?? NULL;
        }
        else {
          // Flat format (legacy fallback)
          $content = $response_data['text'] ?? $response_data['content'] ?? '';
          $role = 'assistant';
          $tool_use_data = $response_data['toolUse'] ?? NULL;
        }
        
        // Create ChatMessage for the response
        $message = new ChatMessage($role, $content);
        
        // Check if response includes tool use
        if ($tool_use_data) {
          $tool_use = $tool_use_data;
          
          // Create ToolsFunctionOutput objects (like Bedrock does)
          $tools = [];
          if ($input instanceof ChatInput && method_exists($input, 'getChatTools') && $input->getChatTools()) {
            $function = $input->getChatTools()->getFunctionByName($tool_use['name']);
            if ($function) {
              $tools[] = new ToolsFunctionOutput(
                $function,
                $tool_use['toolUseId'],
                $tool_use['input']
              );
            }
          }
          
          if (!empty($tools)) {
            $message->setTools($tools);
          }
        }
        
        return new ChatOutput($message, $response_data, NULL);
      }
      
    }
    catch (\Exception $e) {
      throw new \RuntimeException('Chat request failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function embeddings(string|EmbeddingsInput $input, string $model_id, array $tags = []): EmbeddingsOutput {
    // Normalize input - extract text from EmbeddingsInput
    if ($input instanceof EmbeddingsInput) {
      $text = $input->getPrompt();
    }
    else {
      $text = $input;
    }
    
    try {
      // Call API with single text string
      $result = $this->client->embeddings($text, $model_id);
      
      // Extract embedding vector from response
      // API returns: { "embeddings": [...], "model": "...", "usage": {...} }
      $embedding = $result['embeddings'] ?? [];
      
      // EmbeddingsOutput expects array of embeddings (even for single input)
      // Our API returns the vector directly, so wrap it
      return new EmbeddingsOutput([$embedding], $result, []);
      
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
   * Sets whether to use streaming.
   *
   * @param bool $streamed
   *   TRUE to use streaming, FALSE otherwise.
   */
  public function setStreamed(bool $streamed): void {
    $this->streamed = $streamed;
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
   * 
   * Supports multimodal content (images, videos, documents) for Nova models.
   */
  protected function formatMessages(array $messages): array {
    $formatted = [];
    
    foreach ($messages as $message) {
      if ($message instanceof ChatMessage) {
        $content = $message->getText();
        
        // Check if message has attachments (multimodal content)
        // Note: Drupal AI module stores attachments in various ways depending on version
        $has_attachments = method_exists($message, 'getAttachments') && 
                          !empty($message->getAttachments());
        
        if ($has_attachments) {
          // Build multimodal content array
          $content_blocks = [];
          
          // Add attachments first (images, videos, documents)
          foreach ($message->getAttachments() as $attachment) {
            $content_blocks[] = $this->formatAttachment($attachment);
          }
          
          // Add text prompt last
          if ($message->getText()) {
            $content_blocks[] = ['text' => $message->getText()];
          }
          
          $formatted[] = [
            'role' => $message->getRole(),
            'content' => $content_blocks,
          ];
        }
        else {
          // Simple text message
          $formatted[] = [
            'role' => $message->getRole(),
            'content' => $content,
          ];
        }
      }
    }
    
    return $formatted;
  }

  /**
   * Format an attachment for multimodal API request.
   * 
   * @param mixed $attachment
   *   The attachment object from Drupal AI.
   * 
   * @return array
   *   Formatted content block for the API.
   */
  protected function formatAttachment($attachment): array {
    // Determine attachment type
    $type = $attachment['type'] ?? 'image';
    $mime_type = $attachment['mime_type'] ?? $attachment['mimeType'] ?? '';
    
    // Extract format from MIME type
    $format = 'jpeg'; // default
    if (str_contains($mime_type, 'png')) {
      $format = 'png';
    }
    elseif (str_contains($mime_type, 'gif')) {
      $format = 'gif';
    }
    elseif (str_contains($mime_type, 'webp')) {
      $format = 'webp';
    }
    elseif (str_contains($mime_type, 'mp4')) {
      $format = 'mp4';
    }
    elseif (str_contains($mime_type, 'quicktime')) {
      $format = 'mov';
    }
    elseif (str_contains($mime_type, 'webm')) {
      $format = 'webm';
    }
    elseif (str_contains($mime_type, 'pdf')) {
      $format = 'pdf';
    }
    
    // Handle different attachment sources
    if (isset($attachment['uri'])) {
      // S3 URI or file path
      if (str_starts_with($attachment['uri'], 's3://')) {
        // S3 URI - use directly
        return $this->formatS3Content($type, $format, $attachment['uri'], $attachment['name'] ?? NULL);
      }
      else {
        // Local file - convert to base64
        $file_contents = file_get_contents($attachment['uri']);
        if ($file_contents !== FALSE) {
          $base64 = base64_encode($file_contents);
          return $this->formatBase64Content($type, $format, $base64, $attachment['name'] ?? NULL);
        }
      }
    }
    elseif (isset($attachment['data']) || isset($attachment['base64'])) {
      // Base64 encoded data
      $base64 = $attachment['base64'] ?? $attachment['data'];
      return $this->formatBase64Content($type, $format, $base64, $attachment['name'] ?? NULL);
    }
    
    // Fallback to empty text block
    return ['text' => ''];
  }

  /**
   * Format base64 content block.
   */
  protected function formatBase64Content(string $type, string $format, string $base64, ?string $name = NULL): array {
    if ($type === 'image') {
      return [
        'image' => [
          'format' => $format,
          'source' => ['bytes' => $base64],
        ],
      ];
    }
    elseif ($type === 'video') {
      return [
        'video' => [
          'format' => $format,
          'source' => ['bytes' => $base64],
        ],
      ];
    }
    elseif ($type === 'document') {
      return [
        'document' => [
          'format' => $format,
          'name' => $name ?? 'document.' . $format,
          'source' => ['bytes' => $base64],
        ],
      ];
    }
    
    return ['text' => ''];
  }

  /**
   * Format S3 URI content block.
   */
  protected function formatS3Content(string $type, string $format, string $uri, ?string $name = NULL): array {
    if ($type === 'image') {
      return [
        'image' => [
          'format' => $format,
          'source' => [
            's3Location' => ['uri' => $uri],
          ],
        ],
      ];
    }
    elseif ($type === 'video') {
      return [
        'video' => [
          'format' => $format,
          'source' => [
            's3Location' => ['uri' => $uri],
          ],
        ],
      ];
    }
    elseif ($type === 'document') {
      return [
        'document' => [
          'format' => $format,
          'name' => $name ?? 'document.' . $format,
          'source' => [
            's3Location' => ['uri' => $uri],
          ],
        ],
      ];
    }
    
    return ['text' => ''];
  }

  /**
   * Convert Drupal ToolsInput to Quant Cloud API format.
   *
   * @param \Drupal\ai\OperationType\Chat\ToolsInput $tools_input
   *   Drupal tools input.
   *
   * @return array
   *   API-formatted tools.
   */
  protected function formatToolsForApi($tools_input): array {
    // Use renderToolsArray() like AWS Bedrock provider does
    $tools = $tools_input->renderToolsArray();
    $api_tools = [];
    
    foreach ($tools as $tool) {
      $tool_spec = $tool['function'];
      
      // Map 'parameters' to 'inputSchema'
      if (isset($tool['function']['parameters'])) {
        $tool_spec['inputSchema']['json'] = $tool['function']['parameters'];
      }
      else {
        $tool_spec['inputSchema']['json'] = [
          'type' => 'object',
        ];
      }
      
      // Remove 'parameters' as we've moved it to inputSchema
      unset($tool_spec['parameters']);
      
      $api_tools[] = [
        'toolSpec' => $tool_spec,
      ];
    }
    
    return $api_tools;
  }

}

