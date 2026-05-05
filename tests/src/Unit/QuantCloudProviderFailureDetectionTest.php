<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_quant_cloud\Unit;

use Drupal\ai_provider_quant_cloud\Plugin\AiProvider\QuantCloudProvider;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests Quant Cloud provider failure-detection helpers.
 */
#[Group('ai_provider_quant_cloud')]
class QuantCloudProviderFailureDetectionTest extends UnitTestCase {

  /**
   * Tests empty response content detection.
   *
   * @param mixed $content
   *   Response content.
   * @param bool $expected
   *   Expected result.
   *
   */
  #[DataProvider('emptyContentProvider')]
  public function testIsEmptyResponseContent(
    mixed $content,
    bool $expected,
  ): void {
    $this->assertSame(
      $expected,
      $this->provider()->isEmptyResponseContentForTest($content)
    );
  }

  /**
   * Provides response content cases.
   *
   * @return array<string, array{mixed, bool}>
   *   Test cases.
   */
  public static function emptyContentProvider(): array {
    return [
      'empty string' => ['', TRUE],
      'null' => [NULL, TRUE],
      'zero string' => ['0', FALSE],
      'zero integer' => [0, FALSE],
      'empty array' => [[], TRUE],
      'text item' => [[['text' => 'Generated content']], FALSE],
      'empty text item' => [[['text' => '']], TRUE],
      'string item' => [['Generated content'], FALSE],
    ];
  }

  /**
   * Tests token-limit detection.
   *
   * @param string|null $stop_reason
   *   Response stop reason.
   * @param int $output_tokens
   *   Output token count.
   * @param int $max_tokens
   *   Configured token limit.
   * @param bool $expected
   *   Expected result.
   *
   */
  #[DataProvider('tokenLimitProvider')]
  public function testIsLikelyTokenLimited(
    ?string $stop_reason,
    int $output_tokens,
    int $max_tokens,
    bool $expected,
  ): void {
    $this->assertSame(
      $expected,
      $this->provider()->isLikelyTokenLimitedForTest(
        $stop_reason,
        $output_tokens,
        $max_tokens
      )
    );
  }

  /**
   * Provides token-limit cases.
   *
   * @return array<string, array{string|null, int, int, bool}>
   *   Test cases.
   */
  public static function tokenLimitProvider(): array {
    return [
      'explicit max token reason' => ['max_tokens', 100, 4096, TRUE],
      'length reason' => ['length', 100, 4096, TRUE],
      'complete reason with exact token count' => [
        'end_turn',
        4096,
        4096,
        FALSE,
      ],
      'unknown reason with exact token count' => [
        'unknown',
        4096,
        4096,
        TRUE,
      ],
      'missing reason with exact token count' => [
        NULL,
        4096,
        4096,
        TRUE,
      ],
      'missing reason below token count' => [
        NULL,
        4095,
        4096,
        FALSE,
      ],
      'disabled max token limit' => [
        'max_tokens',
        4096,
        0,
        FALSE,
      ],
    ];
  }

  /**
   * Tests output token extraction.
   *
   * @param array $response_data
   *   Response data.
   * @param int $expected
   *   Expected token count.
   *
   */
  #[DataProvider('outputTokenProvider')]
  public function testGetOutputTokenCount(
    array $response_data,
    int $expected,
  ): void {
    $this->assertSame(
      $expected,
      $this->provider()->getOutputTokenCountForTest($response_data)
    );
  }

  /**
   * Provides output token response shapes.
   *
   * @return array<string, array{array, int}>
   *   Test cases.
   */
  public static function outputTokenProvider(): array {
    return [
      'top-level camel case usage' => [
        ['usage' => ['outputTokens' => 123]],
        123,
      ],
      'nested snake case usage' => [
        ['response' => ['usage' => ['output_tokens' => 456]]],
        456,
      ],
      'metadata completion tokens' => [
        ['metadata' => ['usage' => ['completion_tokens' => 789]]],
        789,
      ],
      'missing usage' => [
        [],
        0,
      ],
      'non-array usage' => [
        ['usage' => 'invalid'],
        0,
      ],
    ];
  }

  /**
   * Tests stop reason extraction.
   *
   * @param array $response_data
   *   Response data.
   * @param string|null $expected
   *   Expected stop reason.
   *
   */
  #[DataProvider('stopReasonProvider')]
  public function testGetStopReason(
    array $response_data,
    ?string $expected,
  ): void {
    $this->assertSame(
      $expected,
      $this->provider()->getStopReasonForTest($response_data)
    );
  }

  /**
   * Provides stop reason response shapes.
   *
   * @return array<string, array{array, string|null}>
   *   Test cases.
   */
  public static function stopReasonProvider(): array {
    return [
      'top-level camel case' => [
        ['stopReason' => 'end_turn'],
        'end_turn',
      ],
      'top-level snake case' => [
        ['stop_reason' => 'max_tokens'],
        'max_tokens',
      ],
      'nested camel case' => [
        ['response' => ['stopReason' => 'tool_use']],
        'tool_use',
      ],
      'nested snake case' => [
        ['response' => ['stop_reason' => 'stop']],
        'stop',
      ],
      'missing stop reason' => [
        [],
        NULL,
      ],
    ];
  }

  /**
   * Gets a provider instance without calling the plugin constructor.
   */
  protected function provider(): TestableQuantCloudProvider {
    $reflection = new \ReflectionClass(TestableQuantCloudProvider::class);
    return $reflection->newInstanceWithoutConstructor();
  }

}

/**
 * Test double exposing protected helper methods.
 */
class TestableQuantCloudProvider extends QuantCloudProvider {

  /**
   * Exposes isEmptyResponseContent().
   */
  public function isEmptyResponseContentForTest(mixed $content): bool {
    return $this->isEmptyResponseContent($content);
  }

  /**
   * Exposes isLikelyTokenLimited().
   */
  public function isLikelyTokenLimitedForTest(
    ?string $stop_reason,
    int $output_tokens,
    int $max_tokens,
  ): bool {
    return $this->isLikelyTokenLimited(
      $stop_reason,
      $output_tokens,
      $max_tokens
    );
  }

  /**
   * Exposes getOutputTokenCount().
   */
  public function getOutputTokenCountForTest(array $response_data): int {
    return $this->getOutputTokenCount($response_data);
  }

  /**
   * Exposes getStopReason().
   */
  public function getStopReasonForTest(array $response_data): ?string {
    return $this->getStopReason($response_data);
  }

}
