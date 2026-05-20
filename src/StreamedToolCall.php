<?php

declare(strict_types=1);

namespace Drupal\ai_provider_quant_cloud;

/**
 * Minimal tool-call value object compatible with
 * \Drupal\ai\OperationType\Chat\StreamedChatMessageIterator::assembleToolCalls().
 *
 * The iterator there calls `$tool->toArray()` on every entry returned by
 * `StreamedChatMessage::getTools()`, expecting the OpenAI-shape render array
 * (`id`, `type`, `function: {name, arguments}`). Bedrock-shape providers
 * generate their tool calls as plain arrays — we wrap them in this object so
 * the contract holds.
 */
final readonly class StreamedToolCall {

  public function __construct(
    private string $id,
    private string $name,
    private string $arguments,
  ) {}

  /**
   * Render in the OpenAI-shape expected by the parent iterator's assembler.
   */
  public function toArray(): array {
    return [
      'id' => $this->id,
      'type' => 'function',
      'function' => [
        'name' => $this->name,
        'arguments' => $this->arguments,
      ],
    ];
  }

}
