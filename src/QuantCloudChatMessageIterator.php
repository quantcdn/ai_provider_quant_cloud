<?php

declare(strict_types=1);

namespace Drupal\ai_provider_quant_cloud;

use Drupal\Component\Serialization\Json;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIterator;
use Drupal\ai_provider_quant_cloud\Client\QuantCloudStreamingClient;
use Drupal\ai_provider_quant_cloud\StreamedToolCall;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

/**
 * Iterator for the Quant Cloud dashboard's chat/stream SSE response.
 *
 * The dashboard streams Bedrock-flavoured Server-Sent Events frames over
 * `/api/v3/organisations/{orgId}/ai/chat/stream`. This iterator parses each
 * frame, yields a {@see \Drupal\ai\OperationType\Chat\StreamedChatMessage} per
 * meaningful upstream event, and accumulates state so the base class's
 * `reconstructChatOutput()` can recover the final `ChatMessage` (text + tool
 * calls + token usage) once the stream is drained.
 *
 * Frame vocabulary recognised here:
 *
 *  - Init:           {requestId, model, streaming: true} → metadata only.
 *  - Text delta:     {delta: "...", complete?: bool}     → text fragment.
 *  - Tool announce:  {toolUseId, name}                   → metadata only.
 *  - Tool input:     {name, toolUseId, input: {...}}     → tool call.
 *  - Summary done:   {stopReason, usage, response: {...}} → finish reason
 *                                                          and any sibling
 *                                                          `response.toolUse`.
 *  - Heartbeat/etc:  anything else                       → skipped.
 */
final class QuantCloudChatMessageIterator extends StreamedChatMessageIterator {

  /**
   * The HTTP response stream.
   */
  protected StreamInterface $stream;

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  /**
   * Per-stream state threaded across handleEvent() invocations.
   *
   * Holds:
   *  - emitted_tool_ids: array<string,bool>
   *
   * The dashboard reports the same tool call across several frames (announce,
   * progress, inline-input, and a final summary `response.toolUse[]`). The
   * base `assembleToolCalls()` treats every chunk that carries a non-empty
   * `id` as the start of a new tool call, so emitting the same `toolUseId`
   * twice produces duplicate `ToolsFunctionOutput` entries. Tracking emitted
   * IDs here lets us yield each tool call exactly once per stream.
   *
   * @var array{emitted_tool_ids?: array<string,bool>}
   */
  protected array $state = [];

  /**
   * Create a new iterator from the raw stream.
   *
   * @param \Psr\Http\Message\StreamInterface $stream
   *   The HTTP response body.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger channel for malformed frame warnings.
   *
   * @return static
   *   The iterator instance.
   */
  public static function create(StreamInterface $stream, LoggerInterface $logger): static {
    // The base class wants a Traversable in the constructor; we supply an
    // empty one because doIterate() drives streaming directly.
    $instance = new static(new \ArrayIterator([]));
    $instance->stream = $stream;
    $instance->logger = $logger;
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function doIterate(): \Generator {
    $decodeWarnings = 0;
    $maxWarnings = QuantCloudStreamingClient::MAX_SSE_DECODE_WARNINGS;

    while (!$this->stream->eof()) {
      $line = $this->readLine();

      if ($line === '' || !str_starts_with($line, 'data:')) {
        continue;
      }

      $rawJson = trim(substr($line, strlen('data:')));
      if ($rawJson === '') {
        continue;
      }

      try {
        $event = Json::decode($rawJson);
      }
      catch (\Throwable) {
        $event = NULL;
      }

      if (!is_array($event)) {
        $decodeWarnings++;
        if ($decodeWarnings <= $maxWarnings) {
          $this->logger->warning(
            'Failed to decode SSE JSON data for Quant Cloud streaming response. Warning @count of @limit.',
            ['@count' => $decodeWarnings, '@limit' => $maxWarnings],
          );
        }
        continue;
      }

      yield from $this->handleEvent($event);

      // Some upstream frames include `complete: true` to indicate the stream
      // is finished; respect that as an early exit even before the summary
      // frame ships.
      if (($event['complete'] ?? FALSE) === TRUE) {
        break;
      }
    }
  }

  /**
   * Translate one decoded SSE event into zero or more streamed messages.
   *
   * @param array<string,mixed> $event
   *   The decoded JSON payload of a single `data:` line.
   *
   * @return \Generator<\Drupal\ai\OperationType\Chat\StreamedChatMessageInterface>
   */
  protected function handleEvent(array $event): \Generator {
    $usage = is_array($event['usage'] ?? NULL) ? $event['usage'] : [];
    $yielded = FALSE;

    // Text delta frame.
    if (isset($event['delta']) && is_string($event['delta'])) {
      $message = $this->createStreamedChatMessage(
        'assistant',
        $event['delta'],
        $usage,
        NULL,
        $event,
      );
      $this->applyUsage($message, $usage);
      $yielded = TRUE;
      yield $message;
    }

    // Inline tool input frame (single tool call).
    if (isset($event['toolUseId'], $event['name']) && isset($event['input']) && is_array($event['input'])) {
      $toolUseId = (string) $event['toolUseId'];
      if (!$this->hasEmittedToolId($toolUseId)) {
        $tool = $this->renderToolCall(
          $toolUseId,
          (string) $event['name'],
          $event['input'],
        );
        $this->markToolIdEmitted($toolUseId);
        $message = $this->createStreamedChatMessage(
          'assistant',
          '',
          $usage,
          [$tool],
          $event,
        );
        $this->applyUsage($message, $usage);
        $yielded = TRUE;
        yield $message;
      }
    }

    // Top-level `toolUse` array frame (sibling of `content`).
    if (isset($event['toolUse']) && is_array($event['toolUse'])) {
      foreach ($this->collectToolUses($event['toolUse']) as $tool) {
        $toolUseId = (string) ($tool->toArray()['id'] ?? '');
        if ($toolUseId !== '' && $this->hasEmittedToolId($toolUseId)) {
          continue;
        }
        if ($toolUseId !== '') {
          $this->markToolIdEmitted($toolUseId);
        }
        $message = $this->createStreamedChatMessage(
          'assistant',
          '',
          $usage,
          [$tool],
          $event,
        );
        $this->applyUsage($message, $usage);
        $yielded = TRUE;
        yield $message;
      }
    }

    // Summary / done frame: may contain a nested `response.toolUse` array and
    // the final stopReason. We deliberately ignore `response.content` here —
    // that field carries the *full* accumulated assistant text, which we've
    // already emitted as a sequence of `delta` chunks. Yielding it again
    // would double the message text on reconstruction.
    if (isset($event['response']) && is_array($event['response'])) {
      $response = $event['response'];

      if (isset($response['toolUse']) && is_array($response['toolUse'])) {
        foreach ($this->collectToolUses($response['toolUse']) as $tool) {
          $toolUseId = (string) ($tool->toArray()['id'] ?? '');
          if ($toolUseId !== '' && $this->hasEmittedToolId($toolUseId)) {
            continue;
          }
          if ($toolUseId !== '') {
            $this->markToolIdEmitted($toolUseId);
          }
          $message = $this->createStreamedChatMessage(
            'assistant',
            '',
            $usage,
            [$tool],
            $event,
          );
          $this->applyUsage($message, $usage);
          $yielded = TRUE;
          yield $message;
        }
      }
    }

    // Summary-only frames often carry final token usage without producing a
    // text or tool chunk. Emit an empty chunk so reconstruction can preserve
    // usage metadata while still avoiding duplicated response.content text.
    $is_summary = isset($event['response']) || isset($event['stopReason']);
    if (!$yielded && $usage !== [] && $is_summary) {
      $message = $this->createStreamedChatMessage(
        'assistant',
        '',
        $usage,
        NULL,
        $event,
      );
      $this->applyUsage($message, $usage);
      yield $message;
    }

    if (isset($event['stopReason']) && is_string($event['stopReason'])) {
      $this->setFinishReason($event['stopReason']);
    }
  }

  /**
   * Normalise a list of dashboard toolUse entries into render objects.
   *
   * @param array<int|string,mixed> $toolUses
   *   The `toolUse` array as returned by the dashboard.
   *
   * @return array<int,\Drupal\ai_provider_quant_cloud\StreamedToolCall>
   *   List of tool-call value objects.
   */
  protected function collectToolUses(array $toolUses): array {
    $rendered = [];
    foreach ($toolUses as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $name = (string) ($entry['name'] ?? '');
      $id = (string) ($entry['toolUseId'] ?? $entry['id'] ?? '');
      $input = is_array($entry['input'] ?? NULL) ? $entry['input'] : [];
      if ($name === '' && $id === '') {
        continue;
      }
      $rendered[] = $this->renderToolCall($id, $name, $input);
    }
    return $rendered;
  }

  /**
   * Check whether a tool-use id has already been yielded on this stream.
   */
  protected function hasEmittedToolId(string $toolUseId): bool {
    return isset($this->state['emitted_tool_ids'][$toolUseId]);
  }

  /**
   * Record that a tool-use id has been yielded.
   */
  protected function markToolIdEmitted(string $toolUseId): void {
    $this->state['emitted_tool_ids'][$toolUseId] = TRUE;
  }

  /**
   * Build the tool-call object expected by
   * `StreamedChatMessageIterator::assembleToolCalls()`.
   *
   * @return \Drupal\ai_provider_quant_cloud\StreamedToolCall
   *   A value object whose `toArray()` returns the OpenAI-shape render array.
   */
  protected function renderToolCall(string $toolId, string $name, array $arguments): StreamedToolCall {
    return new StreamedToolCall(
      $toolId,
      $name,
      Json::encode($arguments),
    );
  }

  /**
   * Apply usage data from an SSE frame onto a streamed message.
   *
   * The dashboard surfaces usage as `inputTokens` / `outputTokens` /
   * `totalTokens` on the summary frame; the base class consumes these per
   * chunk to populate the final TokenUsageDto.
   *
   * Note: the StreamedChatMessage setters
   * (setInputTokenUsage/setOutputTokenUsage/setTotalTokenUsage) perform
   * straight assignment rather than accumulation (see
   * \Drupal\ai\OperationType\Chat\StreamedChatMessage lines 158-174), so
   * calling this more than once per logical message is safe — it just
   * overwrites with the latest counts from the most recent frame.
   *
   * @param \Drupal\ai\OperationType\Chat\StreamedChatMessageInterface $message
   *   The chunk to annotate.
   * @param array<string,mixed> $usage
   *   The `usage` block from the SSE event.
   */
  protected function applyUsage($message, array $usage): void {
    if (isset($usage['inputTokens']) && is_numeric($usage['inputTokens'])) {
      $message->setInputTokenUsage((int) $usage['inputTokens']);
    }
    if (isset($usage['outputTokens']) && is_numeric($usage['outputTokens'])) {
      $message->setOutputTokenUsage((int) $usage['outputTokens']);
    }
    if (isset($usage['totalTokens']) && is_numeric($usage['totalTokens'])) {
      $message->setTotalTokenUsage((int) $usage['totalTokens']);
    }
  }

  /**
   * Read one newline-terminated line from the upstream stream.
   *
   * The SSE specification allows lines to be terminated by LF (\n), CR (\r),
   * or CRLF (\r\n). We break on either standalone byte and then strip any
   * trailing \r that may have been buffered alongside the \n.
   *
   * @return string
   *   The line without its trailing newline / carriage return.
   */
  protected function readLine(): string {
    $line = '';
    while (!$this->stream->eof()) {
      $char = $this->stream->read(1);
      if ($char === '' || $char === "\n" || $char === "\r") {
        break;
      }
      $line .= $char;
    }
    return rtrim($line, "\r");
  }

}
