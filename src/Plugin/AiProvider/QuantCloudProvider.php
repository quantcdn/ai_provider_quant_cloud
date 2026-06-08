<?php

declare(strict_types=1);

namespace Drupal\ai_provider_quant_cloud\Plugin\AiProvider;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\Enum\AiModelCapability;
use Drupal\ai\Enum\AiProviderCapability;
use Drupal\ai\Exception\AiSetupFailureException;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInterface;
use Drupal\ai\OperationType\Embeddings\EmbeddingsOutput;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\ai\OperationType\ImageToImage\ImageToImageInput;
use Drupal\ai\OperationType\ImageToImage\ImageToImageInterface;
use Drupal\ai\OperationType\ImageToImage\ImageToImageOutput;
use Drupal\ai\OperationType\TextToImage\TextToImageInput;
use Drupal\ai\OperationType\TextToImage\TextToImageInterface;
use Drupal\ai\OperationType\TextToImage\TextToImageOutput;
use Drupal\ai_provider_quant_cloud\Client\QuantCloudClient;
use Drupal\ai_provider_quant_cloud\Client\QuantCloudStreamingClient;
use Drupal\ai_provider_quant_cloud\QuantCloudChatMessageIterator;
use Drupal\ai_provider_quant_cloud\Service\AuthService;
use Drupal\ai_provider_quant_cloud\Service\ModelsService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Quant Cloud AI Provider plugin.
 *
 * Speaks the Quant Cloud dashboard's native Bedrock-flavoured wire format
 * directly. Chat is implemented inline with a `\Fiber::getCurrent()` check
 * so the AI Agents loop transparently uses SSE under the hood (keeping the
 * connection alive past the dashboard's gateway timeout) while plain HTTP
 * callers see a single buffered response.
 *
 * Embeddings, text-to-image, and image-to-image use the same buffered client.
 */
#[AiProvider(
  id: 'quant_cloud',
  label: new TranslatableMarkup('Quant Cloud AI'),
)]
class QuantCloudProvider extends AiProviderClientBase implements
  ChatInterface,
  EmbeddingsInterface,
  TextToImageInterface,
  ImageToImageInterface {

  /**
   * Buffered HTTP client for the dashboard AI endpoints.
   */
  protected QuantCloudClient $client;

  /**
   * SSE streaming client (shares the same auth/config as the buffered client).
   */
  protected QuantCloudStreamingClient $streamingClient;

  /**
   * Auth service (bearer token + organisation lookup).
   */
  protected AuthService $authService;

  /**
   * Models service (metadata + max output token clamp).
   */
  protected ModelsService $modelsService;

  /**
   * Logger channel.
   */
  protected LoggerInterface $logger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    /** @var static $instance */
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->client = $container->get('ai_provider_quant_cloud.client');
    $instance->streamingClient = $container->get('ai_provider_quant_cloud.streaming_client');
    $instance->authService = $container->get('ai_provider_quant_cloud.auth');
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
    $definition_file = $this->moduleHandler
      ->getModule('ai_provider_quant_cloud')
      ->getPath() . '/definitions/api_defaults.yml';

    return Yaml::parseFile($definition_file);
  }

  /**
   * {@inheritdoc}
   */
  public function isUsable(?string $operation_type = NULL, array $capabilities = []): bool {
    $config = $this->getConfig();

    if (!$config->get('auth.access_token_key') || !$config->get('auth.organization_id')) {
      return FALSE;
    }

    if (!$config->get('platform')) {
      return FALSE;
    }

    if ($operation_type) {
      return in_array($operation_type, $this->getSupportedOperationTypes(), TRUE);
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    return [
      'chat',
      'chat_with_complex_json',
      'chat_with_image_vision',
      'chat_with_structured_response',
      'chat_with_tools',
      'embeddings',
      'text_to_image',
      'image_to_image',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedCapabilities(): array {
    // Adding ChatFiberSupport would tell the base class to drive the SSE
    // iterator through its native Fiber pump, which assumes an OpenAI-shape
    // wire format. Our dashboard ships Bedrock-flavoured frames, so we
    // drive the iterator ourselves (see QuantCloudChatMessageIterator) and
    // only advertise StreamChatOutput so callers can still opt into SSE.
    return [AiProviderCapability::StreamChatOutput];
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    try {
      $feature_map = [
        'chat' => 'chat',
        'chat_with_complex_json' => 'chat',
        'chat_with_image_vision' => 'chat',
        'chat_with_structured_response' => 'chat',
        'chat_with_tools' => 'chat',
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

        if (!empty($capabilities)) {
          $model_capabilities = $model['capabilities'] ?? [];
          $supports_all = TRUE;
          foreach ($capabilities as $capability) {
            $capability_string = $capability instanceof AiModelCapability
              ? $capability->value
              : (string) $capability;
            $capability_flag = $this->mapCapabilityFlag($capability_string);
            if ($capability_flag && empty($model_capabilities[$capability_flag])) {
              $supports_all = FALSE;
              break;
            }
          }
          if (!$supports_all) {
            continue;
          }
        }

        $models[$model_id] = $model['name'] ?? $model_id;
      }

      return $models;
    }
    catch (\Exception) {
      // Provider isn't usable until configuration is complete; return empty
      // so the UI surfaces "no models" rather than throwing during discovery.
      return [];
    }
  }

  /**
   * Map a Drupal capability enum value to the dashboard's model capability flag.
   */
  protected function mapCapabilityFlag(string $capability): ?string {
    return match ($capability) {
      'chat_tools', 'chat_combined_tools_and_structured_response' => 'supportsTools',
      'chat_json_output', 'chat_structured_response' => 'supportsStructuredOutput',
      'chat_with_image_vision' => 'supportsVision',
      'chat_with_video', 'chat_with_audio' => 'supportsMultimodal',
      default => NULL,
    };
  }

  /**
   * {@inheritdoc}
   */
  public function getModelSettings(string $model_id, array $generalConfig = []): array {
    return $generalConfig;
  }

  /**
   * {@inheritdoc}
   *
   * Authentication is managed end-to-end by the {@see AuthService} (OAuth +
   * key store), so the framework-level setter is a no-op. We keep it on the
   * class so the AiProviderInterface contract is satisfied.
   */
  public function setAuthentication(mixed $authentication): void {
    // No-op: AuthService owns the bearer token lifecycle.
  }

  /**
   * {@inheritdoc}
   */
  public function chat(ChatInput|array|string $input, string $model_id, array $tags = []): ChatOutput {
    $this->ensureAuthenticated();

    [$messages, $options] = $this->buildChatPayload($input);
    $options = $this->applyMaxTokensClamp($options, $model_id);

    // Caller explicitly requested streaming (e.g. the AI Explorer UI's
    // "Streamed" checkbox). Return the iterator directly so the caller can
    // pump chunks live.
    if ($this->streamed) {
      $stream = $this->streamingClient->chatStreamRaw($messages, $model_id, $options);
      $iterator = QuantCloudChatMessageIterator::create($stream, $this->logger);
      return new ChatOutput($iterator, [], NULL);
    }

    // Inside a Fiber (Drupal AI Agents loop). Use streaming under the hood to
    // keep the connection alive past the dashboard's gateway timeout, but
    // accumulate into a buffered-style ChatOutput so the caller sees a single
    // complete response.
    if (\Fiber::getCurrent()) {
      $stream = $this->streamingClient->chatStreamRaw($messages, $model_id, $options);
      $iterator = QuantCloudChatMessageIterator::create($stream, $this->logger);
      foreach ($iterator as $chunk) {
        if ($chunk !== NULL) {
          \Fiber::suspend();
        }
      }
      $message = $iterator->reconstructChatOutput()->getNormalized();
      return new ChatOutput($message, [], NULL);
    }

    // Plain buffered path.
    $response = $this->client->chat($messages, $model_id, $options);
    return $this->parseChatResponse($response, $input);
  }

  /**
   * Ensure we have a valid access token before issuing any request.
   *
   * @throws \Drupal\ai\Exception\AiSetupFailureException
   */
  protected function ensureAuthenticated(): void {
    $token = $this->authService->getValidAccessToken();
    if (!$token) {
      throw new AiSetupFailureException('No valid Quant Cloud access token available — re-authenticate via the Quant Cloud AI settings.');
    }
  }

  /**
   * Normalise a chat input into the dashboard's native message + options shape.
   *
   * @return array{0: array<int,array<string,mixed>>, 1: array<string,mixed>}
   *   A two-tuple of [messages, options].
   */
  protected function buildChatPayload(ChatInput|array|string $input): array {
    $messages = [];
    $options = [];

    if ($input instanceof ChatInput) {
      foreach ($input->getMessages() as $message) {
        $messages[] = $this->convertMessage($message);
      }

      $system_prompt = $input->getSystemPrompt();
      if ($system_prompt === '' && $this->chatSystemRole !== '') {
        $system_prompt = $this->chatSystemRole;
      }
      if ($system_prompt !== '') {
        $options['systemPrompt'] = $system_prompt;
      }

      if ($input->getChatTools()) {
        $options['toolConfig'] = [
          'tools' => $this->convertToolsToBedrockShape(
            $input->getChatTools()->renderToolsArray()
          ),
        ];
      }

      $structured = $input->getChatStructuredJsonSchema();
      if (!empty($structured)) {
        $options['response_format'] = $structured;
      }
    }
    elseif (is_array($input)) {
      $messages = $input;
      if ($this->chatSystemRole !== '') {
        $options['systemPrompt'] = $this->chatSystemRole;
      }
    }
    else {
      $messages = [['role' => 'user', 'content' => $input]];
      if ($this->chatSystemRole !== '') {
        $options['systemPrompt'] = $this->chatSystemRole;
      }
    }

    // Merge provider-level configuration (temperature / maxTokens / etc.)
    // last so explicit caller intent (above) wins.
    foreach ($this->configuration as $key => $value) {
      if (!isset($options[$key])) {
        $options[$key] = $value;
      }
    }

    return [$messages, $options];
  }

  /**
   * Convert OpenAI-shaped tool definitions into the Bedrock toolSpec shape.
   *
   * The dashboard's Bedrock Converse chat endpoint requires each tool to be
   * wrapped in a `toolSpec` object with `inputSchema.json`, rather than the
   * OpenAI `{type: function, function: {...}}` envelope produced by Drupal AI.
   *
   * @param array<int,array<string,mixed>> $openAiTools
   *   Tools rendered by ToolsInput::renderToolsArray().
   *
   * @return array<int,array<string,mixed>>
   *   Tools in Bedrock toolSpec shape.
   */
  private function convertToolsToBedrockShape(array $openAiTools): array {
    $bedrockTools = [];
    foreach ($openAiTools as $tool) {
      if (!isset($tool['function']) || !is_array($tool['function'])) {
        continue;
      }
      $fn = $tool['function'];
      $spec = [
        'name' => $fn['name'] ?? '',
        'description' => $fn['description'] ?? '',
      ];
      if (isset($fn['parameters']) && is_array($fn['parameters'])) {
        $spec['inputSchema'] = ['json' => $fn['parameters']];
      }
      else {
        // Bedrock still requires inputSchema even for tools with no params.
        $spec['inputSchema'] = ['json' => ['type' => 'object', 'properties' => new \stdClass()]];
      }
      $bedrockTools[] = ['toolSpec' => $spec];
    }
    return $bedrockTools;
  }

  /**
   * Convert a Drupal AI ChatMessage into the dashboard's native message shape.
   *
   * The dashboard speaks Bedrock Converse — `role` is `user` / `assistant` /
   * `tool`, content is either a string or a list of content blocks, and tool
   * calls round-trip via sibling `toolUse` / `toolResult` entries.
   *
   * @return array<string,mixed>
   */
  protected function convertMessage(ChatMessage $message): array {
    $role = $message->getRole() ?: 'user';
    $text = $message->getText();
    $images = $message->getImages();

    // Tool *result* — the user side of a tool round-trip. Bedrock Converse
    // expects role=user with the result wrapped in a `toolResult` content
    // block (NOT a flat sibling `toolUseId`). Sending the wrong shape causes
    // the upstream loop to lose the tool-result association, so the model
    // thinks its tool call never completed and retries — surfacing as a
    // "saved twice" / "duplicate response" loop in the agent UI.
    //
    // Tool-result messages intentionally drop image attachments. Vision
    // content + tool results in the same message isn't a shape the
    // dashboard accepts today; revisit if Drupal AI Agents starts emitting
    // these together.
    if ($message->getToolsId()) {
      return [
        'role' => 'user',
        'content' => [
          [
            'toolResult' => [
              'toolUseId' => $message->getToolsId(),
              'content' => [['text' => $text]],
            ],
          ],
        ],
      ];
    }

    // Assistant message that previously emitted tool calls. Bedrock Converse
    // expects each tool call inline within the `content` array as a
    // `toolUse` content block, alongside any text block. Sending tool calls
    // as a top-level sibling array (legacy shape) is NOT the format Bedrock
    // round-trips reliably — the upstream loop loses the assistant->tool
    // pairing and the model thinks its previous tool call never happened.
    if ($message->getTools()) {
      $blocks = [];
      if ($text !== '') {
        $blocks[] = ['text' => $text];
      }
      foreach ($message->getTools() as $tool) {
        $tool_use = $this->renderToolUseFromOutput($tool);
        $blocks[] = ['toolUse' => $tool_use];
      }
      return [
        'role' => $role ?: 'assistant',
        'content' => $blocks,
      ];
    }

    // Plain text-only message.
    if (empty($images)) {
      return [
        'role' => $role,
        'content' => $text,
      ];
    }

    // Multimodal: encode images as Bedrock Converse content blocks.
    $blocks = [];
    if ($text !== '') {
      $blocks[] = ['text' => $text];
    }
    foreach ($images as $image) {
      $blocks[] = [
        'image' => [
          'format' => $this->guessImageFormat($image),
          'source' => [
            'bytes' => base64_encode($image->getBinary()),
          ],
        ],
      ];
    }

    return [
      'role' => $role,
      'content' => $blocks,
    ];
  }

  /**
   * Translate a ToolsFunctionOutput back into a sibling toolUse array entry.
   *
   * @return array<string,mixed>
   */
  protected function renderToolUseFromOutput(ToolsFunctionOutput $tool): array {
    $rendered = $tool->getOutputRenderArray();
    $arguments = $rendered['function']['arguments'] ?? '{}';
    $decoded = is_string($arguments) ? (Json::decode($arguments) ?: []) : (array) $arguments;

    return [
      'toolUseId' => $rendered['id'] ?? $tool->getToolId(),
      'name' => $rendered['function']['name'] ?? $tool->getName(),
      'input' => $decoded,
    ];
  }

  /**
   * Guess the upstream image format token from an ImageFile.
   */
  protected function guessImageFormat(ImageFile $image): string {
    $mime = strtolower((string) $image->getMimeType());
    return match (TRUE) {
      str_contains($mime, 'png') => 'png',
      str_contains($mime, 'gif') => 'gif',
      str_contains($mime, 'webp') => 'webp',
      default => 'jpeg',
    };
  }

  /**
   * Clamp an outgoing maxTokens value to the model's hard cap.
   *
   * Mirrors the legacy MaxTokensClampMiddleware behaviour, just inline so we
   * don't need a Guzzle middleware stack for the native client. NULL caps
   * mean "unknown — pass through unchanged".
   *
   * @param array<string,mixed> $options
   *   Outgoing options array (mutable).
   * @param string $model_id
   *   The model the request is destined for.
   *
   * @return array<string,mixed>
   *   The possibly-clamped options.
   */
  protected function applyMaxTokensClamp(array $options, string $model_id): array {
    $config = $this->getConfig();
    $requested = $options['maxTokens']
      ?? $config->get('model.max_tokens')
      ?? QuantCloudClient::DEFAULT_MAX_TOKENS;

    if (!is_numeric($requested)) {
      return $options;
    }
    $requested = (int) $requested;

    $cap = $this->modelsService->getMaxOutputTokens($model_id);
    if ($cap === NULL) {
      $options['maxTokens'] = $requested;
      return $options;
    }
    if ($requested <= $cap) {
      $options['maxTokens'] = $requested;
      return $options;
    }
    $this->logger->warning(
      'Clamping maxTokens for model @model from @requested to model cap @cap',
      [
        '@model' => $model_id,
        '@requested' => $requested,
        '@cap' => $cap,
      ],
    );
    $options['maxTokens'] = $cap;
    return $options;
  }

  /**
   * Parse a buffered dashboard chat response into a ChatOutput.
   *
   * Dashboard response shape:
   * @code
   *   {
   *     "response": {
   *       "role": "assistant",
   *       "content": "...text..." or [content blocks],
   *       "toolUse": [{"toolUseId": "...", "name": "...", "input": {...}}]
   *     },
   *     "usage": {"inputTokens": ..., "outputTokens": ..., "totalTokens": ...},
   *     "requestId": "...",
   *     "modelId": "...",
   *     "stopReason": "end_turn" | "tool_use" | "tool_request" | "max_tokens"
   *   }
   * @endcode
   *
   * Note `response.toolUse` is a SIBLING of `response.content`, not nested.
   */
  protected function parseChatResponse(array $response, ChatInput|array|string $input): ChatOutput {
    $payload = is_array($response['response'] ?? NULL) ? $response['response'] : [];

    $role = (string) ($payload['role'] ?? 'assistant');
    $content = $payload['content'] ?? '';
    $text = $this->flattenContentToText($content);

    $message = new ChatMessage($role, $text);

    $tools_input = ($input instanceof ChatInput) ? $input->getChatTools() : NULL;
    $tools = [];

    if (isset($payload['toolUse']) && is_array($payload['toolUse'])) {
      foreach ($payload['toolUse'] as $entry) {
        if (!is_array($entry)) {
          continue;
        }
        $name = (string) ($entry['name'] ?? '');
        $id = (string) ($entry['toolUseId'] ?? $entry['id'] ?? '');
        $args = is_array($entry['input'] ?? NULL) ? $entry['input'] : [];
        $input_function = $tools_input ? $tools_input->getFunctionByName($name) : NULL;
        $output = new ToolsFunctionOutput($input_function, $id, $args);
        if (!$input_function && $name !== '') {
          $output->setName($name);
        }
        $tools[] = $output;
      }
    }

    if (!empty($tools)) {
      $message->setTools($tools);
    }

    return new ChatOutput($message, $response, []);
  }

  /**
   * Flatten dashboard `content` (string OR list of blocks) into a plain string.
   */
  protected function flattenContentToText(mixed $content): string {
    if (is_string($content)) {
      return $content;
    }
    if (!is_array($content)) {
      return '';
    }
    $text = '';
    foreach ($content as $block) {
      if (is_string($block)) {
        $text .= $block;
        continue;
      }
      if (!is_array($block)) {
        continue;
      }
      if (isset($block['text']) && is_string($block['text'])) {
        $text .= $block['text'];
      }
      elseif (isset($block['type'], $block['text']) && $block['type'] === 'text') {
        $text .= (string) $block['text'];
      }
    }
    return $text;
  }

  /**
   * {@inheritdoc}
   */
  public function embeddings(string|EmbeddingsInput $input, string $model_id, array $tags = []): EmbeddingsOutput {
    $this->ensureAuthenticated();
    $text = $input instanceof EmbeddingsInput ? $input->getPrompt() : $input;

    try {
      $result = $this->client->embeddings($text, $model_id);
      $embedding = $result['embeddings'] ?? [];
      return new EmbeddingsOutput([$embedding], $result, []);
    }
    catch (\Exception $e) {
      throw new \RuntimeException('Embeddings request failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function maxEmbeddingsInput(string $model_id = ''): int {
    return 96;
  }

  /**
   * {@inheritdoc}
   */
  public function embeddingsVectorSize(string $model_id): int {
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
    try {
      $model_details = $this->modelsService->getModelDetails($model_id);
      if ($model_details && isset($model_details['contextWindow'])) {
        return (int) $model_details['contextWindow'];
      }
    }
    catch (\Exception) {
      // Fall through to defaults.
    }

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
    try {
      $model_details = $this->modelsService->getModelDetails($model_id);
      if ($model_details && isset($model_details['maxOutputTokens'])) {
        return (int) $model_details['maxOutputTokens'];
      }
    }
    catch (\Exception) {
      // Fall through to defaults.
    }

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
    return $limits[$model_id] ?? 16384;
  }

  /**
   * {@inheritdoc}
   */
  public function textToImage(string|TextToImageInput $input, string $model_id, array $tags = []): TextToImageOutput {
    $this->ensureAuthenticated();
    $prompt = '';
    $source_images = [];

    if ($input instanceof TextToImageInput) {
      $prompt = $input->getText();
      if (method_exists($input, 'getImages') && !empty($input->getImages())) {
        foreach ($input->getImages() as $image) {
          $source_images[] = base64_encode($image->getBinary());
        }
      }
    }
    else {
      $prompt = $input;
    }

    try {
      $task_type = $this->configuration['task_type'] ?? 'TEXT_IMAGE';
      if (!empty($source_images) && $task_type === 'TEXT_IMAGE') {
        $task_type = 'IMAGE_VARIATION';
      }

      $payload = [
        'modelId' => $model_id,
        'taskType' => $task_type,
        'imageGenerationConfig' => [],
      ];

      switch ($task_type) {
        case 'TEXT_IMAGE':
          $payload['textToImageParams'] = ['text' => $prompt];
          if (!empty($this->configuration['style'])) {
            $payload['textToImageParams']['style'] = $this->configuration['style'];
          }
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
          if (isset($this->configuration['mask_prompt'])) {
            $payload['outPaintingParams']['maskPrompt'] = $this->configuration['mask_prompt'];
          }
          break;

        case 'BACKGROUND_REMOVAL':
          if (empty($source_images)) {
            throw new \InvalidArgumentException('BACKGROUND_REMOVAL requires source image');
          }
          $payload['backgroundRemovalParams'] = ['image' => $source_images[0]];
          break;

        default:
          throw new \InvalidArgumentException("Unsupported task type: {$task_type}");
      }

      $this->applyImageGenerationConfig($payload);

      $response = $this->client->post('image-generation', $payload, [
        'timeout' => 60,
        'connect_timeout' => 10,
      ]);

      if (empty($response['images'])) {
        throw new \RuntimeException('No images returned from API');
      }

      return new TextToImageOutput($this->decodeGeneratedImages($response['images'], 'generated'), $response, []);
    }
    catch (\Exception $e) {
      throw new \RuntimeException('Image generation failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function imageToImage(ImageToImageInput|array|string $input, string $model_id, array $tags = []): ImageToImageOutput {
    $this->ensureAuthenticated();
    $prompt = '';
    $source_images = [];
    $mask_image = NULL;

    if ($input instanceof ImageToImageInput) {
      if (method_exists($input, 'getPrompt')) {
        $prompt = $input->getPrompt() ?? '';
      }
      elseif (method_exists($input, 'getText')) {
        $prompt = $input->getText() ?? '';
      }

      if (method_exists($input, 'getImageFile') && $input->getImageFile()) {
        $source_images[] = base64_encode($input->getImageFile()->getBinary());
      }
      if (method_exists($input, 'getMask') && $input->getMask()) {
        $mask_image = base64_encode($input->getMask()->getBinary());
      }
    }
    elseif (is_array($input)) {
      $prompt = $input['prompt'] ?? $input['text'] ?? '';
      foreach ($input['images'] ?? [] as $image) {
        if ($image instanceof ImageFile) {
          $source_images[] = base64_encode($image->getBinary());
        }
        elseif (is_string($image)) {
          $source_images[] = $image;
        }
      }
    }
    else {
      $prompt = $input;
    }

    if (empty($source_images)) {
      throw new \InvalidArgumentException('Image-to-image requires at least one source image');
    }

    try {
      $task_type = $this->configuration['task_type'] ?? 'IMAGE_VARIATION';

      $payload = [
        'modelId' => $model_id,
        'taskType' => $task_type,
        'imageGenerationConfig' => [],
      ];

      switch ($task_type) {
        case 'IMAGE_VARIATION':
          $payload['imageVariationParams'] = [
            'images' => $source_images,
            'text' => $prompt ?: 'Generate a variation of this image',
          ];
          if (isset($this->configuration['similarity_strength'])) {
            $payload['imageVariationParams']['similarityStrength'] = (float) $this->configuration['similarity_strength'];
          }
          break;

        case 'INPAINTING':
          $payload['inPaintingParams'] = [
            'image' => $source_images[0],
            'text' => $prompt ?: 'Fill the masked region',
          ];
          if ($mask_image !== NULL) {
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
          if (isset($this->configuration['mask_prompt'])) {
            $payload['outPaintingParams']['maskPrompt'] = $this->configuration['mask_prompt'];
          }
          break;

        case 'BACKGROUND_REMOVAL':
          $payload['backgroundRemovalParams'] = ['image' => $source_images[0]];
          break;

        default:
          throw new \InvalidArgumentException("Unsupported task type for image-to-image: {$task_type}");
      }

      $this->applyImageGenerationConfig($payload);

      $response = $this->client->post('image-generation', $payload, [
        'timeout' => 60,
        'connect_timeout' => 10,
      ]);

      if (empty($response['images'])) {
        throw new \RuntimeException('No images returned from API');
      }

      return new ImageToImageOutput($this->decodeGeneratedImages($response['images'], 'variation'), $response, []);
    }
    catch (\Exception $e) {
      throw new \RuntimeException('Image-to-image generation failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Apply shared image-generation configuration options to a request payload.
   */
  protected function applyImageGenerationConfig(array &$payload): void {
    if (isset($this->configuration['width'], $this->configuration['height'])) {
      $payload['imageGenerationConfig']['width'] = (int) $this->configuration['width'];
      $payload['imageGenerationConfig']['height'] = (int) $this->configuration['height'];
    }
    elseif (isset($this->configuration['resolution'])) {
      if ($this->configuration['resolution'] === 'custom') {
        if (isset($this->configuration['custom_width'], $this->configuration['custom_height'])) {
          $payload['imageGenerationConfig']['width'] = (int) $this->configuration['custom_width'];
          $payload['imageGenerationConfig']['height'] = (int) $this->configuration['custom_height'];
        }
      }
      else {
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
    if (isset($this->configuration['seed']) && $this->configuration['seed'] !== NULL) {
      $payload['imageGenerationConfig']['seed'] = (int) $this->configuration['seed'];
    }
    if (isset($this->configuration['nova_canvas_region'])) {
      $payload['region'] = $this->configuration['nova_canvas_region'];
    }
  }

  /**
   * Decode dashboard image-generation results (base64 or data URLs).
   *
   * @return \Drupal\ai\OperationType\GenericType\ImageFile[]
   */
  protected function decodeGeneratedImages(array $imageResults, string $prefix): array {
    $images = [];
    foreach ($imageResults as $index => $data_url) {
      if (str_starts_with($data_url, 'data:image/')) {
        $parts = explode(',', $data_url, 2);
        $base64_data = $parts[1] ?? $data_url;
      }
      else {
        $base64_data = $data_url;
      }

      $image_data = base64_decode($base64_data);
      $format = str_contains($data_url, 'image/png') ? 'png' : 'jpeg';

      $images[] = new ImageFile(
        $image_data,
        "image/{$format}",
        "{$prefix}-{$index}.{$format}",
      );
    }
    return $images;
  }

  /**
   * {@inheritdoc}
   */
  public function requiresImageToImageMask(string $model_id): bool {
    return ($this->configuration['task_type'] ?? 'IMAGE_VARIATION') === 'INPAINTING';
  }

  /**
   * {@inheritdoc}
   */
  public function hasImageToImageMask(string $model_id): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function requiresImageToImagePrompt(string $model_id): bool {
    return ($this->configuration['task_type'] ?? 'IMAGE_VARIATION') !== 'BACKGROUND_REMOVAL';
  }

  /**
   * {@inheritdoc}
   */
  public function hasImageToImagePrompt(string $model_id): bool {
    return TRUE;
  }

}
