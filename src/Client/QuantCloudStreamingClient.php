<?php

declare(strict_types=1);

namespace Drupal\ai_provider_quant_cloud\Client;

use Psr\Http\Message\StreamInterface;

/**
 * Streaming HTTP client for Quant Cloud AI API (Server-Sent Events).
 *
 * Provides SSE streaming support for real-time AI responses.
 */
class QuantCloudStreamingClient extends QuantCloudClient {

  /**
   * Maximum malformed SSE frames to log per streaming request.
   */
  public const MAX_SSE_DECODE_WARNINGS = 3;

  /**
   * Chat with streaming response (SSE) - returns raw stream.
   *
   * Dashboard API route: POST /api/v3/organisations/{orgId}/ai/chat/stream.
   *
   * @param array $messages
   *   Chat messages.
   * @param string $model_id
   *   Model ID.
   * @param array $options
   *   Additional options (response_format, toolConfig, systemPrompt, etc.).
   *
   * @return \Psr\Http\Message\StreamInterface
   *   The raw HTTP response stream for iteration.
   */
  public function chatStreamRaw(array $messages, string $model_id, array $options = []): StreamInterface {
    $config = $this->getConfig();
    $url = $this->buildApiUrl('chat/stream');

    $data = [
      'messages' => $messages,
      'modelId' => $model_id,
      'temperature' => $options['temperature']
        ?? $config->get('model.temperature')
        ?? self::DEFAULT_TEMPERATURE,
      'maxTokens' => $options['maxTokens']
        ?? $config->get('model.max_tokens')
        ?? self::DEFAULT_MAX_TOKENS,
    ];

    // Add structured output (JSON Schema) if provided.
    if (isset($options['response_format'])) {
      $data['response_format'] = $options['response_format'];
    }

    // Add function calling (tools) if provided.
    if (isset($options['toolConfig'])) {
      $data['toolConfig'] = $options['toolConfig'];
    }

    // Add system prompt if provided.
    if (isset($options['systemPrompt'])) {
      $data['systemPrompt'] = $options['systemPrompt'];
    }

    $request_options = [
      'headers' => array_merge($this->getHeaders(), [
    // SSE.
        'Accept' => 'text/event-stream',
      ]),
      'json' => $data,
      'stream' => TRUE,
      'timeout' => $config->get('advanced.streaming_timeout')
        ?? self::DEFAULT_STREAMING_TIMEOUT,
      'connect_timeout' => $config->get('advanced.connect_timeout')
        ?? self::DEFAULT_CONNECT_TIMEOUT,
    ];

    try {
      $response = $this->httpClient->post($url, $request_options);

      // Return the raw stream for the iterator to consume.
      return $response->getBody();

    }
    catch (\Exception $e) {
      $body = '';
      if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->getResponse()) {
        $body = (string) $e->getResponse()->getBody();
      }
      // Government deployments may have PROTECTED data in prompts that flow
      // back through upstream error bodies; only log the body when the
      // operator has opted in via advanced.enable_logging.
      $log_body = $config->get('advanced.enable_logging')
        ? mb_substr($body, 0, 500)
        : '<redacted; enable advanced.enable_logging to capture>';
      $this->logger->error('Streaming request failed: @message body=@body', [
        '@message' => $e->getMessage(),
        '@body' => $log_body,
      ]);
      throw new \RuntimeException('Streaming failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Chat with streaming response (SSE) - legacy buffered version.
   *
   * Dashboard API route: POST /api/v3/organisations/{orgId}/ai/chat/stream.
   *
   * @param array $messages
   *   Chat messages.
   * @param string $model_id
   *   Model ID.
   * @param callable $callback
   *   Callback function to handle each chunk.
   * @param array $options
   *   Additional options (response_format, toolConfig, systemPrompt, etc.).
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
      'temperature' => $options['temperature']
        ?? $config->get('model.temperature')
        ?? self::DEFAULT_TEMPERATURE,
      'maxTokens' => $options['maxTokens']
        ?? $config->get('model.max_tokens')
        ?? self::DEFAULT_MAX_TOKENS,
    ];

    // Add structured output (JSON Schema) if provided.
    if (isset($options['response_format'])) {
      $data['response_format'] = $options['response_format'];
    }

    // Add function calling (tools) if provided.
    if (isset($options['toolConfig'])) {
      $data['toolConfig'] = $options['toolConfig'];
    }

    // Add system prompt if provided.
    if (isset($options['systemPrompt'])) {
      $data['systemPrompt'] = $options['systemPrompt'];
    }

    $request_options = [
      'headers' => array_merge($this->getHeaders(), [
    // SSE.
        'Accept' => 'text/event-stream',
      ]),
      'json' => $data,
      'stream' => TRUE,
      'timeout' => $config->get('advanced.streaming_timeout')
        ?? self::DEFAULT_STREAMING_TIMEOUT,
      'connect_timeout' => $config->get('advanced.connect_timeout')
        ?? self::DEFAULT_CONNECT_TIMEOUT,
    ];

    try {
      $response = $this->httpClient->post($url, $request_options);
      $body = $response->getBody();

      $full_content = '';
      $final_data = NULL;
      $decode_warnings = 0;

      // Read SSE stream.
      while (!$body->eof()) {
        $line = $this->readLine($body);

        // Parse SSE format.
        if (strpos($line, 'data: ') === 0) {
          $json_data = json_decode(substr($line, 6), TRUE);

          if (json_last_error() !== JSON_ERROR_NONE) {
            $decode_warnings++;
            if ($decode_warnings <= self::MAX_SSE_DECODE_WARNINGS) {
              $this->logger->warning(
                'Failed to decode SSE JSON data for streaming response. '
                . 'Warning @count of @limit for this request.',
                [
                  '@count' => $decode_warnings,
                  '@limit' => self::MAX_SSE_DECODE_WARNINGS,
                ]
              );
            }
            continue;
          }

          if (isset($json_data['delta'])) {
            $full_content .= $json_data['delta'];
            $callback($json_data['delta'], FALSE);
          }

          if ($json_data['complete'] ?? FALSE) {
            $final_data = $json_data;
            break;
          }
        }
      }

      return $final_data ?? [
        'response' => ['role' => 'assistant', 'content' => $full_content],
        'complete' => TRUE,
      ];

    }
    catch (\Exception $e) {
      $body = '';
      if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->getResponse()) {
        $body = (string) $e->getResponse()->getBody();
      }
      // Government deployments may have PROTECTED data in prompts that flow
      // back through upstream error bodies; only log the body when the
      // operator has opted in via advanced.enable_logging.
      $log_body = $config->get('advanced.enable_logging')
        ? mb_substr($body, 0, 500)
        : '<redacted; enable advanced.enable_logging to capture>';
      $this->logger->error('Streaming request failed: @message body=@body', [
        '@message' => $e->getMessage(),
        '@body' => $log_body,
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
    // Convert text-to-text to chat message format.
    $messages = [
      [
        'role' => 'user',
        'content' => $prompt,
      ],
    ];

    // Use chatStream for completion.
    return $this->chatStream($messages, $model_id, $callback, $options);
  }

}
