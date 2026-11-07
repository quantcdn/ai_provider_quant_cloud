<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_quant_cloud\Unit;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput;
use Drupal\ai\OperationType\Chat\Tools\ToolsInput;
use Drupal\ai_provider_quant_cloud\Client\QuantCloudClient;
use Drupal\ai_provider_quant_cloud\Plugin\AiProvider\QuantCloudProvider;
use Drupal\ai_provider_quant_cloud\Service\ModelsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Tests Quant Cloud provider helpers that don't require a Drupal kernel.
 *
 * Covers the protected payload-shaping helpers exercised by chat() — the
 * maxTokens clamp, structured-output payload normalisation, and dashboard
 * response parsing — using a TestableQuantCloudProvider subclass that
 * exposes them and stubs out injected services.
 */
#[Group('ai_provider_quant_cloud')]
class QuantCloudProviderTest extends UnitTestCase {

  /**
   * Build a provider with stub dependencies and optional config overrides.
   *
   * @param array<string,mixed> $config_values
   *   Map of config key (e.g. "model.max_tokens") to value, used by
   *   $provider->getConfig()->get($key) inside the SUT.
   * @param array<string,int> $model_caps
   *   Map of model id to its maxOutputTokens cap. Models absent from the
   *   map cause ModelsService::getMaxOutputTokens() to return NULL.
   */
  protected function provider(
    array $config_values = [],
    array $model_caps = [],
  ): TestableQuantCloudProvider {
    $reflection = new \ReflectionClass(TestableQuantCloudProvider::class);
    /** @var TestableQuantCloudProvider $provider */
    $provider = $reflection->newInstanceWithoutConstructor();

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(static fn (string $key) => $config_values[$key] ?? NULL);
    $provider->setConfigStub($config);

    $models_service = $this->createMock(ModelsService::class);
    $models_service->method('getMaxOutputTokens')
      ->willReturnCallback(static fn (string $model) => $model_caps[$model] ?? NULL);
    $this->setProtected($provider, 'modelsService', $models_service);

    $this->setProtected($provider, 'logger', new NullLogger());
    $this->setProtected($provider, 'chatSystemRole', '');
    $this->setProtected($provider, 'configuration', []);

    return $provider;
  }

  /**
   * Helper to write a protected/private property without going through DI.
   */
  protected function setProtected(object $object, string $property, mixed $value): void {
    $reflection = new \ReflectionObject($object);
    while ($reflection !== FALSE && !$reflection->hasProperty($property)) {
      $reflection = $reflection->getParentClass();
    }
    if ($reflection === FALSE) {
      throw new \RuntimeException("Property {$property} not found");
    }
    $prop = $reflection->getProperty($property);
    $prop->setAccessible(TRUE);
    $prop->setValue($object, $value);
  }

  /**
   * Provides the maxTokens clamp scenarios.
   *
   * Covers the regression around config-and-default fallback values: prior
   * to the fix the clamp returned early unless the caller had set
   * maxTokens explicitly, so the dashboard could receive an over-the-cap
   * value sourced from site config or QuantCloudClient::DEFAULT_MAX_TOKENS.
   *
   * @return array<string, array{
   *   options: array<string,mixed>,
   *   config: array<string,mixed>,
   *   caps: array<string,int>,
   *   model: string,
   *   expected: int|null
   * }>
   *   Test cases.
   */
  public static function maxTokenClampProvider(): array {
    return [
      'caller value within cap is preserved' => [
        'options' => ['maxTokens' => 1000],
        'config' => [],
        'caps' => ['nova' => 5000],
        'model' => 'nova',
        'expected' => 1000,
      ],
      'caller value above cap is clamped' => [
        'options' => ['maxTokens' => 9000],
        'config' => [],
        'caps' => ['nova' => 5000],
        'model' => 'nova',
        'expected' => 5000,
      ],
      'config max_tokens within cap is preserved' => [
        'options' => [],
        'config' => ['model.max_tokens' => 4000],
        'caps' => ['nova' => 5000],
        'model' => 'nova',
        'expected' => 4000,
      ],
      'config max_tokens above cap is clamped (regression guard)' => [
        'options' => [],
        'config' => ['model.max_tokens' => 32768],
        'caps' => ['nova' => 5000],
        'model' => 'nova',
        'expected' => 5000,
      ],
      'default value above cap is clamped (regression guard)' => [
        'options' => [],
        'config' => [],
        'caps' => ['nova' => 5000],
        'model' => 'nova',
        // QuantCloudClient::DEFAULT_MAX_TOKENS (32768) is over the cap.
        'expected' => 5000,
      ],
      'unknown cap passes through with effective value populated' => [
        'options' => [],
        'config' => ['model.max_tokens' => 16384],
        'caps' => [],
        'model' => 'unknown',
        'expected' => 16384,
      ],
      'unknown cap with no config falls back to DEFAULT_MAX_TOKENS' => [
        'options' => [],
        'config' => [],
        'caps' => [],
        'model' => 'unknown',
        'expected' => QuantCloudClient::DEFAULT_MAX_TOKENS,
      ],
    ];
  }

  /**
   * Tests that applyMaxTokensClamp() always writes the effective maxTokens.
   *
   * @param array<string,mixed> $options
   *   Caller options.
   * @param array<string,mixed> $config
   *   Config overrides keyed by config path.
   * @param array<string,int> $caps
   *   Model-id → cap map (empty = cap unknown).
   * @param string $model
   *   Model id passed to applyMaxTokensClamp.
   * @param int|null $expected
   *   Expected resolved maxTokens after clamping.
   */
  #[DataProvider('maxTokenClampProvider')]
  public function testApplyMaxTokensClamp(
    array $options,
    array $config,
    array $caps,
    string $model,
    ?int $expected,
  ): void {
    $provider = $this->provider($config, $caps);
    $result = $provider->applyMaxTokensClampForTest($options, $model);

    $this->assertSame($expected, $result['maxTokens'] ?? NULL);
  }

  /**
   * Non-numeric caller maxTokens is left untouched.
   *
   * Mirrors the early return in the production code for malformed
   * caller-supplied options (e.g. an empty string from a misconfigured
   * AI Defaults entry).
   */
  public function testApplyMaxTokensClampIgnoresNonNumeric(): void {
    $provider = $this->provider();
    $result = $provider->applyMaxTokensClampForTest(
      ['maxTokens' => 'not-a-number'],
      'nova'
    );
    $this->assertSame('not-a-number', $result['maxTokens']);
  }

  /**
   * Structured-output schemas are wrapped in the dashboard's response_format.
   *
   * Drupal AI's normalised schema is `{name, description, strict, schema}`.
   * The dashboard expects `{type: "json_schema", schema, name, strict}`.
   * Forwarding the normalised shape directly returned 400 on every call —
   * this test pins the wrapper.
   */
  public function testBuildChatPayloadWrapsStructuredOutput(): void {
    $provider = $this->provider();

    $chat = new ChatInput([new ChatMessage('user', 'Hi')]);
    $chat->setChatStructuredJsonSchema([
      'name' => 'answer_schema',
      'strict' => TRUE,
      'schema' => [
        'type' => 'object',
        'properties' => ['answer' => ['type' => 'string']],
      ],
    ]);

    [, $options] = $provider->buildChatPayloadForTest($chat);

    $this->assertArrayHasKey('response_format', $options);
    $this->assertSame('json_schema', $options['response_format']['type']);
    $this->assertSame('answer_schema', $options['response_format']['name']);
    $this->assertTrue($options['response_format']['strict']);
    $this->assertSame(
      [
        'type' => 'object',
        'properties' => ['answer' => ['type' => 'string']],
      ],
      $options['response_format']['schema'],
    );
  }

  /**
   * Defaults fill in for missing schema metadata.
   */
  public function testBuildChatPayloadStructuredOutputDefaults(): void {
    $provider = $this->provider();

    $chat = new ChatInput([new ChatMessage('user', 'Hi')]);
    $chat->setChatStructuredJsonSchema([
      'schema' => ['type' => 'object'],
    ]);

    [, $options] = $provider->buildChatPayloadForTest($chat);

    $this->assertSame('json_schema', $options['response_format']['name']);
    $this->assertFalse($options['response_format']['strict']);
  }

  /**
   * Tools are converted from the OpenAI render shape into Bedrock toolSpec.
   */
  public function testBuildChatPayloadConvertsToolsToBedrockShape(): void {
    $provider = $this->provider();

    $function = new ToolsFunctionInput('lookup_node');
    $function->setDescription('Find a node');
    $tools_input = new ToolsInput([$function]);
    $chat = new ChatInput([new ChatMessage('user', 'Hi')]);
    $chat->setChatTools($tools_input);

    [, $options] = $provider->buildChatPayloadForTest($chat);

    $this->assertArrayHasKey('toolConfig', $options);
    $this->assertCount(1, $options['toolConfig']['tools']);
    $spec = $options['toolConfig']['tools'][0]['toolSpec'] ?? NULL;
    $this->assertNotNull($spec);
    $this->assertSame('lookup_node', $spec['name']);
    $this->assertSame('Find a node', $spec['description']);
    $this->assertArrayNotHasKey('parameters', $spec);
    $this->assertArrayHasKey('inputSchema', $spec);
    $this->assertArrayHasKey('json', $spec['inputSchema']);
    $this->assertSame('object', $spec['inputSchema']['json']['type']);
    $this->assertInstanceOf(\stdClass::class, $spec['inputSchema']['json']['properties']);
  }

  /**
   * Plain-text dashboard responses round-trip into a ChatMessage.
   */
  public function testParseChatResponsePlainText(): void {
    $provider = $this->provider();

    $output = $provider->parseChatResponseForTest(
      [
        'response' => [
          'role' => 'assistant',
          'content' => 'Hello, world!',
        ],
      ],
      'ignored',
    );

    $message = $output->getNormalized();
    $this->assertSame('assistant', $message->getRole());
    $this->assertSame('Hello, world!', $message->getText());
    $this->assertNull($message->getTools());
  }

  /**
   * Content-block dashboard responses are flattened into a single string.
   */
  public function testParseChatResponseFlattensContentBlocks(): void {
    $provider = $this->provider();

    $output = $provider->parseChatResponseForTest(
      [
        'response' => [
          'role' => 'assistant',
          'content' => [
            ['text' => 'Hello, '],
            ['type' => 'text', 'text' => 'world!'],
            'trailing string',
          ],
        ],
      ],
      'ignored',
    );

    $this->assertSame(
      'Hello, world!trailing string',
      $output->getNormalized()->getText(),
    );
  }

  /**
   * Sibling toolUse arrays are converted into ToolsFunctionOutput entries.
   *
   * The dashboard returns `response.toolUse` as a sibling of
   * `response.content`; this test pins that the parser preserves the
   * tool id, name, and decoded input even when the chat input had no
   * matching tool definition.
   */
  public function testParseChatResponseExtractsToolUseEntries(): void {
    $provider = $this->provider();

    $output = $provider->parseChatResponseForTest(
      [
        'response' => [
          'role' => 'assistant',
          'content' => '',
          'toolUse' => [
            [
              'toolUseId' => 'tu-42',
              'name' => 'lookup_node',
              'input' => ['nid' => 7],
            ],
          ],
        ],
      ],
      'ignored',
    );

    $tools = $output->getNormalized()->getTools();
    $this->assertIsArray($tools);
    $this->assertCount(1, $tools);
    $this->assertSame('tu-42', $tools[0]->getToolId());
    $this->assertSame('lookup_node', $tools[0]->getName());
    $rendered = $tools[0]->getOutputRenderArray();
    $this->assertSame(
      ['nid' => 7],
      Json::decode($rendered['function']['arguments']),
    );
  }

}

/**
 * Test double exposing protected helpers and stubbing getConfig().
 */
class TestableQuantCloudProvider extends QuantCloudProvider {

  /**
   * The config stub used by getConfig().
   */
  private ImmutableConfig $configStub;

  /**
   * Inject a config stub that backs the protected getConfig() helper.
   */
  public function setConfigStub(ImmutableConfig $config): void {
    $this->configStub = $config;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfig(): ImmutableConfig {
    return $this->configStub;
  }

  /**
   * Exposes applyMaxTokensClamp().
   */
  public function applyMaxTokensClampForTest(
    array $options,
    string $model_id,
  ): array {
    return $this->applyMaxTokensClamp($options, $model_id);
  }

  /**
   * Exposes buildChatPayload().
   */
  public function buildChatPayloadForTest(
    ChatInput|array|string $input,
  ): array {
    return $this->buildChatPayload($input);
  }

  /**
   * Exposes parseChatResponse().
   */
  public function parseChatResponseForTest(
    array $response,
    ChatInput|array|string $input,
  ): \Drupal\ai\OperationType\Chat\ChatOutput {
    return $this->parseChatResponse($response, $input);
  }

}
