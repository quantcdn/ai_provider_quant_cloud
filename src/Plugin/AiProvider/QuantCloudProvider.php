<?php

namespace Drupal\ai_provider_quant_cloud\Plugin\AiProvider;

use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\Component\Serialization\Json;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInterface;
use Drupal\ai\OperationType\Embeddings\EmbeddingsOutput;
use Drupal\ai\OperationType\TextToImage\TextToImageInput;
use Drupal\ai\OperationType\TextToImage\TextToImageInterface;
use Drupal\ai\OperationType\TextToImage\TextToImageOutput;
use Drupal\ai\OperationType\ImageToImage\ImageToImageInput;
use Drupal\ai\OperationType\ImageToImage\ImageToImageInterface;
use Drupal\ai\OperationType\ImageToImage\ImageToImageOutput;
use Drupal\ai\OperationType\GenericType\ImageFile;
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
  EmbeddingsInterface,
  TextToImageInterface,
  ImageToImageInterface {

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
      'text_to_image',
      'image_to_image',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguredModels(?string $operation_type = NULL, $capabilities = []): array {
    // Fetch models dynamically from the API via ModelsService
    try {
      // Get models for the operation type (defaults to 'chat' if not specified)
      // Map Drupal operation types to API features
      $feature_map = [
        'chat' => 'chat',
        'embeddings' => 'embeddings',
        'text_to_image' => 'image_generation',
        'image_to_image' => 'image_generation',
      ];
      $feature = $feature_map[$operation_type] ?? ($operation_type ?: 'chat');
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
    
    // Check for system prompt - use chatSystemRole from base class (like OpenAI provider)
    // This is set via setChatSystemRole() by agents before calling chat()
    if ($this->chatSystemRole) {
      $options['systemPrompt'] = $this->chatSystemRole;
    }
    // Fallback: also check input object (for backward compatibility)
    elseif (method_exists($input, 'getSystemRole') && $input->getSystemRole()) {
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
        // toolUse can be a single object or an array of tool requests
        if ($tool_use_data) {
          // Normalize to array - handle both single tool and array of tools
          $tool_use_array = [];
          if (isset($tool_use_data['name'])) {
            // Single tool object
            $tool_use_array = [$tool_use_data];
          }
          elseif (is_array($tool_use_data) && !empty($tool_use_data)) {
            // Array of tool objects (check first element has 'name')
            if (isset($tool_use_data[0]['name'])) {
              $tool_use_array = $tool_use_data;
            }
          }

          // Create ToolsFunctionOutput objects (like Bedrock does)
          $tools = [];
          if (!empty($tool_use_array) && $input instanceof ChatInput && method_exists($input, 'getChatTools') && $input->getChatTools()) {
            foreach ($tool_use_array as $tool_use) {
              $function = $input->getChatTools()->getFunctionByName($tool_use['name']);
              if ($function) {
                $tools[] = new ToolsFunctionOutput(
                  $function,
                  $tool_use['toolUseId'] ?? uniqid('tool_'),
                  $tool_use['input'] ?? []
                );
              }
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
  public function textToImage(string|TextToImageInput $input, string $model_id, array $tags = []): TextToImageOutput {
    // Normalize input and extract images if provided
    $prompt = '';
    $source_images = [];
    
    if ($input instanceof TextToImageInput) {
      $prompt = $input->getText();
      // Check if input has images (for image-to-image operations)
      if (method_exists($input, 'getImages') && !empty($input->getImages())) {
        foreach ($input->getImages() as $image) {
          // Convert Drupal ImageFile to base64
          $source_images[] = base64_encode($image->getBinary());
        }
      }
    }
    else {
      $prompt = $input;
    }

    try {
      // Determine task type based on configuration and presence of source images
      $task_type = $this->configuration['task_type'] ?? 'TEXT_IMAGE';
      
      // Auto-detect: if images provided but task type is TEXT_IMAGE, switch to IMAGE_VARIATION
      if (!empty($source_images) && $task_type === 'TEXT_IMAGE') {
        $task_type = 'IMAGE_VARIATION';
      }

      // Build image generation request for Nova Canvas
      $payload = [
        'modelId' => $model_id,
        'taskType' => $task_type,
        'imageGenerationConfig' => [],
      ];

      // Build task-specific parameters
      switch ($task_type) {
        case 'TEXT_IMAGE':
          $payload['textToImageParams'] = [
            'text' => $prompt,
          ];
          
          // Add style if specified (Nova Canvas visual styles)
          if (!empty($this->configuration['style'])) {
            $payload['textToImageParams']['style'] = $this->configuration['style'];
          }
          
          // Add negative prompt if specified (what NOT to include)
          if (!empty($this->configuration['negativePrompt'])) {
            $payload['textToImageParams']['negativeText'] = $this->configuration['negativePrompt'];
          }
          break;

        case 'IMAGE_VARIATION':
          if (empty($source_images)) {
            throw new \InvalidArgumentException('IMAGE_VARIATION requires source image(s)');
          }
          
          $payload['imageVariationParams'] = [
            'images' => $source_images,
            'text' => $prompt ?: 'Generate a variation of this image',
          ];
          
          // Similarity strength (0.2-1.0, higher = more similar to original)
          if (isset($this->configuration['similarity_strength'])) {
            $payload['imageVariationParams']['similarityStrength'] = (float) $this->configuration['similarity_strength'];
          }
          break;

        case 'INPAINTING':
          if (empty($source_images)) {
            throw new \InvalidArgumentException('INPAINTING requires source image and mask');
          }
          
          $payload['inPaintingParams'] = [
            'image' => $source_images[0],
            'text' => $prompt ?: 'Fill the masked region',
          ];
          
          // Mask image is typically the second image
          if (isset($source_images[1])) {
            $payload['inPaintingParams']['maskImage'] = $source_images[1];
          }
          break;

        case 'OUTPAINTING':
          if (empty($source_images)) {
            throw new \InvalidArgumentException('OUTPAINTING requires source image');
          }
          
          $payload['outPaintingParams'] = [
            'image' => $source_images[0],
            'text' => $prompt ?: 'Extend the image borders',
          ];
          
          // Optional mask prompt for directional expansion
          if (isset($this->configuration['mask_prompt'])) {
            $payload['outPaintingParams']['maskPrompt'] = $this->configuration['mask_prompt'];
          }
          break;

        case 'BACKGROUND_REMOVAL':
          if (empty($source_images)) {
            throw new \InvalidArgumentException('BACKGROUND_REMOVAL requires source image');
          }
          
          $payload['backgroundRemovalParams'] = [
            'image' => $source_images[0],
          ];
          break;

        default:
          throw new \InvalidArgumentException("Unsupported task type: {$task_type}");
      }

      // Add optional configuration from provider settings
      if (isset($this->configuration['width']) && isset($this->configuration['height'])) {
        $payload['imageGenerationConfig']['width'] = (int) $this->configuration['width'];
        $payload['imageGenerationConfig']['height'] = (int) $this->configuration['height'];
      }
      elseif (isset($this->configuration['resolution'])) {
        // Handle 'custom' resolution option
        if ($this->configuration['resolution'] === 'custom') {
          if (isset($this->configuration['custom_width']) && isset($this->configuration['custom_height'])) {
            $payload['imageGenerationConfig']['width'] = (int) $this->configuration['custom_width'];
            $payload['imageGenerationConfig']['height'] = (int) $this->configuration['custom_height'];
          }
        }
        else {
          // Support resolution format like "1024x1024"
          $parts = explode('x', $this->configuration['resolution']);
          if (count($parts) === 2) {
            $payload['imageGenerationConfig']['width'] = (int) $parts[0];
            $payload['imageGenerationConfig']['height'] = (int) $parts[1];
          }
        }
      }

      if (isset($this->configuration['quality'])) {
        $payload['imageGenerationConfig']['quality'] = $this->configuration['quality'];
      }

      if (isset($this->configuration['numberOfImages'])) {
        $payload['imageGenerationConfig']['numberOfImages'] = (int) $this->configuration['numberOfImages'];
      }

      if (isset($this->configuration['cfgScale'])) {
        $payload['imageGenerationConfig']['cfgScale'] = (float) $this->configuration['cfgScale'];
      }

      if (isset($this->configuration['seed']) && $this->configuration['seed'] !== null) {
        $payload['imageGenerationConfig']['seed'] = (int) $this->configuration['seed'];
      }

      // Nova Canvas requires specific regions (us-east-1, ap-northeast-1, eu-west-1)
      // Default to us-east-1 if not specified
      if (isset($this->configuration['nova_canvas_region'])) {
        $payload['region'] = $this->configuration['nova_canvas_region'];
      }

      // Call image generation API with extended timeout (image generation can take 10-30s)
      $response = $this->client->post('image-generation', $payload, [
        'timeout' => 60,        // 60s timeout for image generation (handles premium + multiple images)
        'connect_timeout' => 10,
      ]);

      if (empty($response['images'])) {
        throw new \RuntimeException('No images returned from API');
      }

      // API returns compressed thumbnail data URLs (data:image/jpeg;base64,...)
      // Extract actual base64 data for Drupal
      $images = [];
      foreach ($response['images'] as $index => $data_url) {
        // Check if it's a data URL or raw base64
        if (str_starts_with($data_url, 'data:image/')) {
          // Extract base64 from data URL: data:image/jpeg;base64,<data>
          $parts = explode(',', $data_url, 2);
          $base64_data = $parts[1] ?? $data_url;
        }
        else {
          // Already raw base64
          $base64_data = $data_url;
        }
        
        $image_data = base64_decode($base64_data);
        
        // Determine format from data URL MIME type or default to JPEG (thumbnails are JPEG)
        $format = 'jpeg';
        if (str_contains($data_url, 'image/png')) {
          $format = 'png';
        }
        
        $images[] = new ImageFile(
          $image_data, 
          "image/{$format}", 
          "generated-{$index}.{$format}"
        );
      }

      return new TextToImageOutput($images, $response, []);
    }
    catch (\Exception $e) {
      throw new \RuntimeException('Image generation failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function imageToImage(ImageToImageInput|array|string $input, string $model_id, array $tags = []): ImageToImageOutput {
    // Normalize input and extract images
    $prompt = '';
    $source_images = [];
    
    if ($input instanceof ImageToImageInput) {
      // ImageToImageInput might use getPrompt() instead of getText()
      $prompt = '';
      if (method_exists($input, 'getPrompt')) {
        $prompt = $input->getPrompt() ?? '';
      }
      elseif (method_exists($input, 'getText')) {
        $prompt = $input->getText() ?? '';
      }
      
      // Get the source image using getImageFile()
      if (method_exists($input, 'getImageFile') && $input->getImageFile()) {
        $image = $input->getImageFile();
        $source_images[] = base64_encode($image->getBinary());
      }
      
      // Get the mask image if present (for INPAINTING)
      $mask_image = NULL;
      if (method_exists($input, 'getMask') && $input->getMask()) {
        $mask = $input->getMask();
        $mask_image = base64_encode($mask->getBinary());
      }
    }
    elseif (is_array($input)) {
      // Handle array input format (from forms/AJAX)
      $prompt = $input['prompt'] ?? $input['text'] ?? '';
      
      // Extract images from array
      if (isset($input['images']) && is_array($input['images'])) {
        foreach ($input['images'] as $image) {
          if ($image instanceof ImageFile) {
            $source_images[] = base64_encode($image->getBinary());
          }
          elseif (is_string($image)) {
            // Already base64 encoded
            $source_images[] = $image;
          }
        }
      }
    }
    else {
      // String input is just the prompt
      $prompt = $input;
    }

    if (empty($source_images)) {
      throw new \InvalidArgumentException('Image-to-image requires at least one source image');
    }

    try {
      // Determine task type from configuration (default to IMAGE_VARIATION)
      $task_type = $this->configuration['task_type'] ?? 'IMAGE_VARIATION';

      // Build image generation request for Nova Canvas
      $payload = [
        'modelId' => $model_id,
        'taskType' => $task_type,
        'imageGenerationConfig' => [],
      ];

      // Build task-specific parameters
      switch ($task_type) {
        case 'IMAGE_VARIATION':
          $payload['imageVariationParams'] = [
            'images' => $source_images,
            'text' => $prompt ?: 'Generate a variation of this image',
          ];
          
          // Similarity strength (0.2-1.0, higher = more similar to original)
          if (isset($this->configuration['similarity_strength'])) {
            $payload['imageVariationParams']['similarityStrength'] = (float) $this->configuration['similarity_strength'];
          }
          break;

        case 'INPAINTING':
          $payload['inPaintingParams'] = [
            'image' => $source_images[0],
            'text' => $prompt ?: 'Fill the masked region',
          ];
          
          // Use mask from input if available, otherwise try second image
          if (isset($mask_image)) {
            $payload['inPaintingParams']['maskImage'] = $mask_image;
          }
          elseif (isset($source_images[1])) {
            $payload['inPaintingParams']['maskImage'] = $source_images[1];
          }
          break;

        case 'OUTPAINTING':
          $payload['outPaintingParams'] = [
            'image' => $source_images[0],
            'text' => $prompt ?: 'Extend the image borders',
          ];
          
          // Optional mask prompt for directional expansion
          if (isset($this->configuration['mask_prompt'])) {
            $payload['outPaintingParams']['maskPrompt'] = $this->configuration['mask_prompt'];
          }
          break;

        case 'BACKGROUND_REMOVAL':
          $payload['backgroundRemovalParams'] = [
            'image' => $source_images[0],
          ];
          break;

        default:
          throw new \InvalidArgumentException("Unsupported task type for image-to-image: {$task_type}");
      }

      // Add optional configuration from provider settings
      if (isset($this->configuration['width']) && isset($this->configuration['height'])) {
        $payload['imageGenerationConfig']['width'] = (int) $this->configuration['width'];
        $payload['imageGenerationConfig']['height'] = (int) $this->configuration['height'];
      }
      elseif (isset($this->configuration['resolution'])) {
        // Handle 'custom' resolution option
        if ($this->configuration['resolution'] === 'custom') {
          if (isset($this->configuration['custom_width']) && isset($this->configuration['custom_height'])) {
            $payload['imageGenerationConfig']['width'] = (int) $this->configuration['custom_width'];
            $payload['imageGenerationConfig']['height'] = (int) $this->configuration['custom_height'];
          }
        }
        else {
          // Support resolution format like "1024x1024"
          $parts = explode('x', $this->configuration['resolution']);
          if (count($parts) === 2) {
            $payload['imageGenerationConfig']['width'] = (int) $parts[0];
            $payload['imageGenerationConfig']['height'] = (int) $parts[1];
          }
        }
      }

      if (isset($this->configuration['quality'])) {
        $payload['imageGenerationConfig']['quality'] = $this->configuration['quality'];
      }

      if (isset($this->configuration['numberOfImages'])) {
        $payload['imageGenerationConfig']['numberOfImages'] = (int) $this->configuration['numberOfImages'];
      }

      if (isset($this->configuration['cfgScale'])) {
        $payload['imageGenerationConfig']['cfgScale'] = (float) $this->configuration['cfgScale'];
      }

      if (isset($this->configuration['seed']) && $this->configuration['seed'] !== null) {
        $payload['imageGenerationConfig']['seed'] = (int) $this->configuration['seed'];
      }

      // Nova Canvas requires specific regions (us-east-1, ap-northeast-1, eu-west-1)
      if (isset($this->configuration['nova_canvas_region'])) {
        $payload['region'] = $this->configuration['nova_canvas_region'];
      }

      // Call image generation API with extended timeout
      $response = $this->client->post('image-generation', $payload, [
        'timeout' => 60,        // 60s timeout for image generation
        'connect_timeout' => 10,
      ]);

      if (empty($response['images'])) {
        throw new \RuntimeException('No images returned from API');
      }

      // API returns compressed thumbnail data URLs (data:image/jpeg;base64,...)
      // Extract actual base64 data for Drupal
      $images = [];
      foreach ($response['images'] as $index => $data_url) {
        // Check if it's a data URL or raw base64
        if (str_starts_with($data_url, 'data:image/')) {
          // Extract base64 from data URL: data:image/jpeg;base64,<data>
          $parts = explode(',', $data_url, 2);
          $base64_data = $parts[1] ?? $data_url;
        }
        else {
          $base64_data = $data_url;
        }
        
        $image_data = base64_decode($base64_data);
        
        // Determine format (JPEG for thumbnails, PNG for originals)
        $format = str_contains($data_url, 'image/png') ? 'png' : 'jpeg';
        
        $images[] = new ImageFile(
          $image_data, 
          "image/{$format}", 
          "variation-{$index}.{$format}"
        );
      }

      return new ImageToImageOutput($images, $response, []);
    }
    catch (\Exception $e) {
      throw new \RuntimeException('Image-to-image generation failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function requiresImageToImageMask(string $model_id): bool {
    // Get the configured task type
    $task_type = $this->configuration['task_type'] ?? 'IMAGE_VARIATION';
    
    // INPAINTING requires a mask image
    return $task_type === 'INPAINTING';
  }

  /**
   * {@inheritdoc}
   */
  public function hasImageToImageMask(string $model_id): bool {
    // Nova Canvas supports mask-based operations (INPAINTING)
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function requiresImageToImagePrompt(string $model_id): bool {
    // Get the configured task type
    $task_type = $this->configuration['task_type'] ?? 'IMAGE_VARIATION';
    
    // BACKGROUND_REMOVAL doesn't require a prompt, others do (or benefit from one)
    return $task_type !== 'BACKGROUND_REMOVAL';
  }

  /**
   * {@inheritdoc}
   */
  public function hasImageToImagePrompt(string $model_id): bool {
    // Nova Canvas supports text prompts for all image-to-image operations
    return TRUE;
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
  public function embeddingsVectorSize(string $model_id): int {
    // Return known dimensions for supported embedding models.
    // This is more reliable than calling the API to figure it out.
    $dimensions = [
      'amazon.titan-embed-text-v2:0' => 1024,
      'amazon.titan-embed-text-v1' => 1536,
      'cohere.embed-english-v3' => 1024,
      'cohere.embed-multilingual-v3' => 1024,
    ];

    return $dimensions[$model_id] ?? 1024;
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
   * Converts Drupal AI's tool_result role to Bedrock's user role with toolResult content block.
   * Formats assistant messages with tool calls to include toolUse content blocks.
   */
  protected function formatMessages(array $messages): array {
    $formatted = [];

    foreach ($messages as $message) {
      if ($message instanceof ChatMessage) {
        $role = $message->getRole();
        $content = $message->getText();

        // Handle assistant messages with tool calls first
        // Include toolUse blocks in content array for conversation continuity
        // (Must check before tool_result since assistant messages should not be converted)
        $tools = $message->getTools();
        if ($role === 'assistant' && !empty($tools)) {
          $content_blocks = [];

          // Add text content first if present
          if ($content) {
            $content_blocks[] = ['text' => $content];
          }

          // Add tool use blocks - use getRenderedTools() like AWS Bedrock provider
          $tool_uses = $message->getRenderedTools();
          foreach ($tool_uses as $tool_use) {
            $content_blocks[] = [
              'toolUse' => [
                'toolUseId' => $tool_use['id'],
                'name' => $tool_use['function']['name'],
                // AWS wants the structured object, not the string
                'input' => Json::decode($tool_use['function']['arguments']),
              ],
            ];
          }

          $formatted[] = [
            'role' => 'assistant',
            'content' => $content_blocks,
          ];
          continue;
        }

        // Handle tool_result messages - convert to Bedrock format
        // Drupal AI uses role "tool" or has toolsId set, Bedrock expects role "user" with toolResult content block
        // Match AWS Bedrock provider pattern: (role === 'tool' || getToolsId()) && role !== 'assistant'
        if (($role === 'tool' || $role === 'tool_result' || $message->getToolsId()) && $role !== 'assistant') {
          $formatted[] = [
            'role' => 'user',
            'content' => [
              [
                'toolResult' => [
                  'toolUseId' => $message->getToolsId(),
                  'content' => [
                    // Need to set text to tool result, if empty use placeholder
                    ['text' => $content !== '' ? $content : 'Tool Result'],
                  ],
                ],
              ],
            ],
          ];
          continue;
        }

        // Check if message has images (multimodal content)
        // Drupal AI module uses getImages() returning ImageFile objects
        $images = method_exists($message, 'getImages') ? $message->getImages() : [];
        $has_images = !empty($images);

        if ($has_images) {
          // Build multimodal content array
          $content_blocks = [];

          // Add images first
          foreach ($images as $image) {
            $content_blocks[] = $this->formatImageFile($image);
          }

          // Add text prompt last
          if ($message->getText()) {
            $content_blocks[] = ['text' => $message->getText()];
          }

          $formatted[] = [
            'role' => $role,
            'content' => $content_blocks,
          ];
        }
        else {
          // Simple text message
          $formatted[] = [
            'role' => $role,
            'content' => $content,
          ];
        }
      }
    }

    return $formatted;
  }

  /**
   * Format an ImageFile for multimodal API request.
   *
   * @param \Drupal\ai\OperationType\GenericType\ImageFile $image
   *   The ImageFile object from Drupal AI.
   *
   * @return array
   *   Formatted content block for the API.
   */
  protected function formatImageFile($image): array {
    $mime_type = $image->getMimeType();
    $binary = $image->getBinary();

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

    // Return Bedrock/Claude image format
    return [
      'image' => [
        'format' => $format,
        'source' => ['bytes' => base64_encode($binary)],
      ],
    ];
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

