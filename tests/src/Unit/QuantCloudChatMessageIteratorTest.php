<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_quant_cloud\Unit;

use Drupal\ai_provider_quant_cloud\QuantCloudChatMessageIterator;
use Drupal\ai_provider_quant_cloud\StreamedToolCall;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

/**
 * Tests the Quant Cloud SSE iterator's frame-by-frame parsing.
 *
 * The iterator translates dashboard-flavoured SSE frames into the
 * StreamedChatMessage shape the base `ai` module expects. These tests pin
 * each branch of handleEvent() against a synthetic stream of `data:` lines.
 *
 * @covers \Drupal\ai_provider_quant_cloud\QuantCloudChatMessageIterator
 */
#[Group('ai_provider_quant_cloud')]
class QuantCloudChatMessageIteratorTest extends UnitTestCase {

  /**
   * Build an iterator from a string of newline-separated SSE lines.
   *
   * @param string $sse
   *   The raw SSE bytes.
   * @param \Psr\Log\LoggerInterface|null $logger
   *   Optional logger. Defaults to a NullLogger.
   */
  protected function makeIterator(string $sse, $logger = NULL): QuantCloudChatMessageIterator {
    return QuantCloudChatMessageIterator::create(
      Utils::streamFor($sse),
      $logger ?? new NullLogger(),
    );
  }

  /**
   * Consume the iterator and return its emitted messages.
   *
   * @return \Drupal\ai\OperationType\Chat\StreamedChatMessageInterface[]
   *   Messages emitted during iteration.
   */
  protected function drain(QuantCloudChatMessageIterator $iterator): array {
    return iterator_to_array($iterator->doIterate(), FALSE);
  }

  /**
   * Init frame ({requestId, model, streaming: true}) emits nothing.
   */
  public function testInitFrameYieldsNothing(): void {
    $sse = 'data: {"requestId":"req-1","model":"amazon.nova-lite-v1:0","streaming":true}' . "\n";

    $messages = $this->drain($this->makeIterator($sse));

    $this->assertSame([], $messages, 'Init frame must not emit a streamed message.');
  }

  /**
   * Text delta frame emits a single assistant message carrying the delta.
   */
  public function testTextDeltaFrameYieldsAssistantMessage(): void {
    $sse = 'data: {"delta":"Hello, "}' . "\n"
      . 'data: {"delta":"world!"}' . "\n";

    $messages = $this->drain($this->makeIterator($sse));

    $this->assertCount(2, $messages);
    $this->assertSame('assistant', $messages[0]->getRole());
    $this->assertSame('Hello, ', $messages[0]->getText());
    $this->assertSame('world!', $messages[1]->getText());
  }

  /**
   * Inline tool-input frame yields one tool call in OpenAI shape.
   */
  public function testInlineToolInputFrameYieldsToolCall(): void {
    $input = ['nid' => 42, 'lang' => 'en'];
    $sse = 'data: {"toolUseId":"tu-1","name":"lookup_node","input":' . json_encode($input) . '}' . "\n";

    $messages = $this->drain($this->makeIterator($sse));

    $this->assertCount(1, $messages);
    $tools = $messages[0]->getTools();
    $this->assertIsArray($tools);
    $this->assertCount(1, $tools);
    $this->assertInstanceOf(StreamedToolCall::class, $tools[0]);

    $array = $tools[0]->toArray();
    $this->assertSame('tu-1', $array['id']);
    $this->assertSame('function', $array['type']);
    $this->assertSame('lookup_node', $array['function']['name']);
    $this->assertSame(json_encode($input), $array['function']['arguments']);
  }

  /**
   * Summary frame with response.toolUse[] yields one message per tool use.
   */
  public function testSummaryResponseToolUseYieldsPerToolMessages(): void {
    $payload = [
      'stopReason' => 'tool_use',
      'response' => [
        'toolUse' => [
          ['toolUseId' => 't1', 'name' => 'alpha', 'input' => ['a' => 1]],
          ['toolUseId' => 't2', 'name' => 'beta', 'input' => ['b' => 2]],
        ],
      ],
    ];
    $sse = 'data: ' . json_encode($payload) . "\n";

    $messages = $this->drain($this->makeIterator($sse));

    $this->assertCount(2, $messages);
    $first = $messages[0]->getTools()[0]->toArray();
    $this->assertSame('t1', $first['id']);
    $this->assertSame('alpha', $first['function']['name']);
    $this->assertSame('{"a":1}', $first['function']['arguments']);

    $second = $messages[1]->getTools()[0]->toArray();
    $this->assertSame('t2', $second['id']);
    $this->assertSame('beta', $second['function']['name']);
    $this->assertSame('{"b":2}', $second['function']['arguments']);
  }

  /**
   * Summary frame sets the finish reason on the iterator.
   */
  public function testSummaryFrameSetsFinishReason(): void {
    $sse = 'data: {"stopReason":"end_turn","response":{}}' . "\n";

    $iterator = $this->makeIterator($sse);
    $this->drain($iterator);

    $this->assertSame('end_turn', $iterator->getFinishReason());
  }

  /**
   * Malformed JSON on `data:` lines is skipped without exception and only the
   * first three decode failures are logged.
   */
  public function testMalformedJsonIsSkippedAndLogsCapped(): void {
    $sse = '';
    for ($i = 0; $i < 5; $i++) {
      $sse .= 'data: not-json-' . $i . "\n";
    }

    $logger = new class() extends AbstractLogger {
      /** @var array<int,array{level:mixed,message:string|\Stringable}> */
      public array $records = [];

      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->records[] = ['level' => $level, 'message' => $message];
      }
    };
    $messages = $this->drain($this->makeIterator($sse, $logger));

    $this->assertSame([], $messages, 'Malformed frames must not emit messages.');
    $warnings = array_filter(
      $logger->records,
      static fn (array $record): bool => $record['level'] === 'warning'
    );
    $this->assertCount(3, $warnings, 'Only the first three decode failures are logged.');
  }

  /**
   * Unknown event shapes (no delta / response / inline tool) yield nothing.
   */
  public function testUnknownEventYieldsNothing(): void {
    $sse = 'data: {"heartbeat":true,"ts":1234}' . "\n";

    $messages = $this->drain($this->makeIterator($sse));

    $this->assertSame([], $messages);
  }

  /**
   * The same toolUseId arriving twice (inline + summary) emits only once.
   *
   * The dashboard ships each tool call across multiple frames: an inline
   * `{toolUseId, name, input}` frame and a summary `response.toolUse[]` entry
   * with the identical id. The base assembler treats every non-empty `id` as
   * the start of a new tool call, so without dedup we'd produce two
   * `ToolsFunctionOutput` rows for one logical call — the agent then sees
   * the duplicate in its history and self-corrects in a loop.
   */
  public function testDoIterateDeduplicatesToolCallsAcrossInlineAndSummaryFrames(): void {
    $input = ['nid' => 42];
    $inline = [
      'toolUseId' => 'tu-shared',
      'name' => 'lookup_node',
      'input' => $input,
    ];
    $summary = [
      'stopReason' => 'tool_use',
      'response' => [
        'toolUse' => [
          ['toolUseId' => 'tu-shared', 'name' => 'lookup_node', 'input' => $input],
        ],
      ],
    ];
    $sse = 'data: ' . json_encode($inline) . "\n"
      . 'data: ' . json_encode($summary) . "\n";

    $messages = $this->drain($this->makeIterator($sse));

    $seenIds = [];
    foreach ($messages as $message) {
      foreach ($message->getTools() ?? [] as $tool) {
        $this->assertInstanceOf(StreamedToolCall::class, $tool);
        $seenIds[] = $tool->toArray()['id'];
      }
    }

    $this->assertSame(
      ['tu-shared'],
      $seenIds,
      'A toolUseId that appears in both inline and summary frames must be emitted exactly once.',
    );
  }

  /**
   * response.content on the summary frame must NOT be re-emitted as a delta.
   *
   * The dashboard ships the accumulated assistant text on the summary frame
   * after every delta has already streamed; emitting it again would cause
   * the reconstructed ChatMessage to contain doubled text.
   */
  public function testSummaryResponseContentIsNotReEmittedAsDelta(): void {
    $sse = 'data: {"delta":"Hello, world!"}' . "\n"
      . 'data: {"stopReason":"end_turn","response":{"content":"Hello, world!"}}' . "\n";

    $messages = $this->drain($this->makeIterator($sse));

    // We expect exactly one assistant text message — the delta — and no
    // second message echoing response.content back through the pipeline.
    $textOutputs = array_map(
      static fn ($message): string => $message->getText(),
      $messages,
    );
    $this->assertSame(['Hello, world!'], array_values(array_filter(
      $textOutputs,
      static fn (string $text): bool => $text !== '',
    )));
  }

  /**
   * Summary-only usage frames are preserved for output reconstruction.
   *
   * The dashboard sends token usage on the final summary frame. Text-only
   * responses often have no `response.toolUse`, so the iterator must still
   * yield an empty usage-bearing chunk or reconstruction loses token counts.
   */
  public function testSummaryUsageWithoutToolUseIsPreserved(): void {
    $summary = [
      'stopReason' => 'end_turn',
      'usage' => [
        'inputTokens' => 12,
        'outputTokens' => 5,
        'totalTokens' => 17,
      ],
      'response' => [
        'content' => 'Hello, world!',
      ],
    ];
    $sse = 'data: {"delta":"Hello, world!"}' . "\n"
      . 'data: ' . json_encode($summary) . "\n";

    $iterator = $this->makeIterator($sse);
    $messages = $this->drain($iterator);

    $this->assertCount(2, $messages);
    $this->assertSame('', $messages[1]->getText());
    $this->assertSame(12, $messages[1]->getInputTokenUsage());
    $this->assertSame(5, $messages[1]->getOutputTokenUsage());
    $this->assertSame(17, $messages[1]->getTotalTokenUsage());

    $output = $iterator->reconstructChatOutput();
    $this->assertSame('Hello, world!', $output->getNormalized()->getText());
    $this->assertSame(12, $output->getTokenUsage()->input);
    $this->assertSame(5, $output->getTokenUsage()->output);
    $this->assertSame(17, $output->getTokenUsage()->total);
  }

}
