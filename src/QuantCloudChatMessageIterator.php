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
        $line_count = 0;
        $chunk_count = 0;
        
        $this->logger->info('🌊 Starting to parse SSE stream');
        
        while (!$this->stream->eof()) {
          $line = $this->readLine();
          $line_count++;
          
          if ($line_count <= 3) {
            $this->logger->info('📝 SSE Line @num: "@line"', [
              '@num' => $line_count,
              '@line' => substr($line, 0, 100) . (strlen($line) > 100 ? '...' : ''),
            ]);
          }
          
          if (strpos($line, 'data: ') === 0) {
            $json_data = json_decode(substr($line, 6), TRUE);
            
            if ($json_data === NULL) {
              $this->logger->warning('⚠️  Failed to decode JSON');
              continue;
            }
            
            if (isset($json_data['delta'])) {
              $chunk_count++;
              
              if ($chunk_count <= 5) {
                $this->logger->info('📦 Yielding chunk @num', ['@num' => $chunk_count]);
              }
              
              yield [
                'delta' => $json_data['delta'],
                'role' => $json_data['role'] ?? 'assistant',
                'usage' => $json_data['usage'] ?? [],
              ];
            }
            
            if ($json_data['complete'] ?? FALSE) {
              $this->logger->info('🏁 Stream complete - chunks: @count', ['@count' => $chunk_count]);
              break;
            }
          }
        }
        
        $this->logger->info('✅ Stream finished - Lines: @lines, Chunks: @chunks', [
          '@lines' => $line_count,
          '@chunks' => $chunk_count,
        ]);
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
    $logger->info('🌊 QuantCloudChatMessageIterator created');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getIterator(): \Generator {
    $this->logger->info('🔄 getIterator() called - starting to iterate over parsed stream');
    
    $message_count = 0;
    foreach ($this->iterator as $data) {
      $message_count++;
      
      if ($message_count <= 3) {
        $this->logger->info('🔄 Creating StreamedChatMessage @num: role=@role, delta="@delta"', [
          '@num' => $message_count,
          '@role' => $data['role'] ?? 'assistant',
          '@delta' => substr($data['delta'] ?? '', 0, 50),
        ]);
      }
      
      yield new StreamedChatMessage(
        $data['role'] ?? 'assistant',
        $data['delta'] ?? '',
        $data['usage'] ?? []
      );
    }
    
    $this->logger->info('✅ getIterator() finished - yielded @count messages', [
      '@count' => $message_count,
    ]);
  }

}

