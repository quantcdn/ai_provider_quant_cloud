<?php

namespace Drupal\ai_provider_quant_cloud\Client;

/**
 * Streaming HTTP client for Quant Cloud AI API (Server-Sent Events).
 *
 * Provides SSE streaming support for real-time AI responses.
 */
class QuantCloudStreamingClient extends QuantCloudClient {

  /**
   * Chat with streaming response (SSE) - returns raw stream.
   * 
   * Dashboard API route: POST /api/v3/organisations/{orgId}/ai/chat/stream
   *
   * @param array $messages
   *   Chat messages.
   * @param string $model_id
   *   Model ID.
   * @param array $options
   *   Additional options (responseFormat, toolConfig, systemPrompt, etc.).
   *
   * @return \Psr\Http\Message\StreamInterface
   *   The raw HTTP response stream for iteration.
   */
  public function chatStreamRaw(array $messages, string $model_id, array $options = []): \Psr\Http\Message\StreamInterface {
    $config = $this->getConfig();
    $url = $this->buildApiUrl('chat/stream');
    
    $data = [
      'messages' => $messages,
      'modelId' => $model_id,
      'temperature' => $options['temperature'] ?? $config->get('model.temperature') ?? 0.7,
      'maxTokens' => $options['maxTokens'] ?? $config->get('model.max_tokens') ?? 1000,
    ];
    
    // Add structured output (JSON Schema) if provided
    if (isset($options['responseFormat'])) {
      $data['responseFormat'] = $options['responseFormat'];
    }
    
    // Add function calling (tools) if provided
    if (isset($options['toolConfig'])) {
      $data['toolConfig'] = $options['toolConfig'];
    }
    
    // Add system prompt if provided
    if (isset($options['systemPrompt'])) {
      $data['systemPrompt'] = $options['systemPrompt'];
    }
    
    $request_options = [
      'headers' => array_merge($this->getHeaders(), [
        'Accept' => 'text/event-stream', // SSE
      ]),
      'json' => $data,
      'stream' => TRUE,
      'timeout' => $config->get('advanced.streaming_timeout') ?? 60,
    ];
    
    try {
      $this->logger->info('🌐 HTTP POST to streaming endpoint: @url', ['@url' => $url]);
      
      $response = $this->httpClient->post($url, $request_options);
      $status_code = $response->getStatusCode();
      
      $this->logger->info('📡 HTTP Response: @status - Returning raw stream', ['@status' => $status_code]);
      
      // Return the raw stream for the iterator to consume
      return $response->getBody();
      
    }
    catch (\Exception $e) {
      $this->logger->error('❌ Streaming request failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new \RuntimeException('Streaming failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Chat with streaming response (SSE) - legacy buffered version.
   * 
   * Dashboard API route: POST /api/v3/organisations/{orgId}/ai/chat/stream
   *
   * @param array $messages
   *   Chat messages.
   * @param string $model_id
   *   Model ID.
   * @param callable $callback
   *   Callback function to handle each chunk.
   * @param array $options
   *   Additional options (responseFormat, toolConfig, systemPrompt, etc.).
   *
   * @return array
   *   Final response data.
   *
   * @deprecated Use chatStreamRaw() and QuantCloudChatMessageIterator instead.
   */
  public function chatStream(array $messages, string $model_id, callable $callback, array $options = []): array {
    $config = $this->getConfig();
    $url = $this->buildApiUrl('chat/stream');
    
    $data = [
      'messages' => $messages,
      'modelId' => $model_id,
      'temperature' => $options['temperature'] ?? $config->get('model.temperature') ?? 0.7,
      'maxTokens' => $options['maxTokens'] ?? $config->get('model.max_tokens') ?? 1000,
    ];
    
    // Add structured output (JSON Schema) if provided
    if (isset($options['responseFormat'])) {
      $data['responseFormat'] = $options['responseFormat'];
    }
    
    // Add function calling (tools) if provided
    if (isset($options['toolConfig'])) {
      $data['toolConfig'] = $options['toolConfig'];
    }
    
    // Add system prompt if provided
    if (isset($options['systemPrompt'])) {
      $data['systemPrompt'] = $options['systemPrompt'];
    }
    
    $request_options = [
      'headers' => array_merge($this->getHeaders(), [
        'Accept' => 'text/event-stream', // SSE
      ]),
      'json' => $data,
      'stream' => TRUE,
      'timeout' => $config->get('advanced.streaming_timeout') ?? 60,
    ];
    
    try {
      $this->logger->info('🌐 HTTP POST to streaming endpoint: @url', ['@url' => $url]);
      
      $response = $this->httpClient->post($url, $request_options);
      $status_code = $response->getStatusCode();
      
      $this->logger->info('📡 HTTP Response: @status', ['@status' => $status_code]);
      
      $body = $response->getBody();
      
      $full_content = '';
      $final_data = NULL;
      $line_count = 0;
      
      // Read SSE stream
      while (!$body->eof()) {
        $line = $this->readLine($body);
        $line_count++;
        
        if ($line_count <= 3) {
          $this->logger->info('📝 SSE Line @num: "@line"', [
            '@num' => $line_count,
            '@line' => substr($line, 0, 100) . (strlen($line) > 100 ? '...' : ''),
          ]);
        }
        
        // Parse SSE format
        if (strpos($line, 'data: ') === 0) {
          $json_data = json_decode(substr($line, 6), TRUE);
          
          if ($json_data === NULL) {
            $this->logger->warning('⚠️ Failed to decode JSON from SSE line: @line', [
              '@line' => substr($line, 0, 200),
            ]);
            continue;
          }
          
          if (isset($json_data['delta'])) {
            $full_content .= $json_data['delta'];
            $callback($json_data['delta'], FALSE);
          }
          
          if ($json_data['complete'] ?? FALSE) {
            $this->logger->info('🏁 Stream complete signal received');
            $final_data = $json_data;
            break;
          }
        }
      }
      
      $this->logger->info('📊 Stream finished - Lines read: @lines, Content length: @length', [
        '@lines' => $line_count,
        '@length' => strlen($full_content),
      ]);
      
      return $final_data ?? [
        'response' => ['role' => 'assistant', 'content' => $full_content],
        'complete' => TRUE,
      ];
      
    }
    catch (\Exception $e) {
      $this->logger->error('❌ Streaming request failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new \RuntimeException('Streaming failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Read a line from stream.
   */
  protected function readLine($stream): string {
    $line = '';
    while (!$stream->eof()) {
      $char = $stream->read(1);
      if ($char === "\n") {
        break;
      }
      $line .= $char;
    }
    return trim($line);
  }

  /**
   * Completion with streaming (SSE).
   * 
   * Note: Uses chat/stream endpoint as Dashboard API doesn't have a separate
   * completion endpoint. Converts prompt to chat message format.
   */
  public function completeStream(string $prompt, string $model_id, callable $callback, array $options = []): array {
    $config = $this->getConfig();
    
    // Convert text-to-text to chat message format
    $messages = [
      [
        'role' => 'user',
        'content' => $prompt,
      ],
    ];
    
    // Use chatStream for completion
    return $this->chatStream($messages, $model_id, $callback, $options);
  }

}

