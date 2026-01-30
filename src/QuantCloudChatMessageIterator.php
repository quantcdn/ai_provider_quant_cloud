<?php

namespace Drupal\ai_provider_quant_cloud;

use Drupal\ai\OperationType\Chat\StreamedChatMessage;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIterator;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

/**
 * Quant Cloud Chat message iterator for streaming responses.
 */
class QuantCloudChatMessageIterator extends StreamedChatMessageIterator {

  /**
   * The HTTP response stream.
   *
   * @var \Psr\Http\Message\StreamInterface
   */
  protected StreamInterface $stream;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Creates a QuantCloudChatMessageIterator.
   *
   * @param \Psr\Http\Message\StreamInterface $stream
   *   The HTTP response stream.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   *
   * @return static
   *   The iterator instance.
   */
  public static function create(StreamInterface $stream, LoggerInterface $logger): static {
    // Create wrapper that implements IteratorAggregate
    $wrapper = new class($stream, $logger) implements \IteratorAggregate {
      private StreamInterface $stream;
      private LoggerInterface $logger;
      
      public function __construct(StreamInterface $stream, LoggerInterface $logger) {
        $this->stream = $stream;
        $this->logger = $logger;
      }
      
      public function getIterator(): \Generator {
        while (!$this->stream->eof()) {
          $line = $this->readLine();

          if (strpos($line, 'data: ') === 0) {
            $raw_json = substr($line, 6);
            $json_data = json_decode($raw_json, TRUE);

            if ($json_data === NULL) {
              $this->logger->warning('Failed to decode SSE JSON data');
              continue;
            }

            if (isset($json_data['delta'])) {
              yield [
                'delta' => $json_data['delta'],
                'role' => $json_data['role'] ?? 'assistant',
                'usage' => $json_data['usage'] ?? [],
              ];
            }

            // Handle tool use events - yield tool data for the iterator consumer
            if (isset($json_data['toolUse'])) {
              yield [
                'delta' => '',
                'role' => $json_data['role'] ?? 'assistant',
                'usage' => $json_data['usage'] ?? [],
                'toolUse' => $json_data['toolUse'],
                'stopReason' => $json_data['stopReason'] ?? NULL,
                'content' => $json_data['content'] ?? '',
              ];
            }

            // Also handle tool_request events from done event
            if (isset($json_data['response']['toolUse'])) {
              yield [
                'delta' => '',
                'role' => 'assistant',
                'usage' => $json_data['usage'] ?? [],
                'toolUse' => $json_data['response']['toolUse'],
                'stopReason' => $json_data['stopReason'] ?? NULL,
                'content' => $json_data['response']['content'] ?? '',
              ];
            }

            if ($json_data['complete'] ?? FALSE) {
              break;
            }
          }
        }
      }
      
      private function readLine(): string {
        $line = '';
        while (!$this->stream->eof()) {
          $char = $this->stream->read(1);
          if ($char === "\n") break;
          $line .= $char;
        }
        return trim($line);
      }
    };
    
    $instance = new static($wrapper);
    $instance->stream = $stream;
    $instance->logger = $logger;
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getIterator(): \Generator {
    foreach ($this->iterator as $data) {
      yield new StreamedChatMessage(
        $data['role'] ?? 'assistant',
        $data['delta'] ?? '',
        $data['usage'] ?? []
      );
    }
  }

}

