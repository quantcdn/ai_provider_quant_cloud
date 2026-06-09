<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_quant_cloud\Unit;

use Drupal\Component\Serialization\Json;
use Drupal\ai_provider_quant_cloud\StreamedToolCall;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the StreamedToolCall value object's OpenAI-shape contract.
 *
 * @covers \Drupal\ai_provider_quant_cloud\StreamedToolCall
 */
#[Group('ai_provider_quant_cloud')]
class StreamedToolCallTest extends UnitTestCase {

  /**
   * Verifies toArray() returns the OpenAI shape consumed by the iterator.
   *
   * Mirrors the keys read by
   * \Drupal\ai\OperationType\Chat\StreamedChatMessageIterator::assembleToolCalls().
   */
  public function testToArrayReturnsOpenAiShape(): void {
    $tool = new StreamedToolCall(
      'tool-use-123',
      'lookup_node',
      Json::encode(['nid' => 42]),
    );

    $array = $tool->toArray();

    $this->assertSame('tool-use-123', $array['id']);
    $this->assertSame('function', $array['type']);
    $this->assertIsArray($array['function']);
    $this->assertSame('lookup_node', $array['function']['name']);
    $this->assertSame('{"nid":42}', $array['function']['arguments']);
  }

  /**
   * Empty input → arguments encoded as `{}` (JSON object literal).
   *
   * `Json::encode([])` historically renders `[]`, but the renderToolCall()
   * helper inside QuantCloudChatMessageIterator always passes the dashboard
   * `input` block, which is `{}` on no-args tools. Confirm that an empty
   * JSON-object string survives the value object verbatim.
   */
  public function testEmptyArgumentsRoundTripAsObject(): void {
    $tool = new StreamedToolCall(
      'tool-use-empty',
      'noop',
      '{}',
    );

    $this->assertSame('{}', $tool->toArray()['function']['arguments']);
  }

  /**
   * Special characters survive JSON encode/decode through the value object.
   */
  public function testSpecialCharactersRoundTrip(): void {
    $payload = [
      'query' => "Hello \"world\" \nwith newlines and unicode: \u{1F600}",
      'flag' => TRUE,
      'count' => 7,
    ];
    $encoded = Json::encode($payload);

    $tool = new StreamedToolCall('id-x', 'search', $encoded);

    $arguments = $tool->toArray()['function']['arguments'];
    $this->assertSame($encoded, $arguments);
    $this->assertEquals($payload, Json::decode($arguments));
  }

}
