<?php

declare(strict_types=1);

namespace Drupal\ai_provider_quant_cloud;

/**
 * Minimal tool-call value object compatible with
 * \Drupal\ai\OperationType\Chat\StreamedChatMessageIterator::assembleToolCalls().
 *
 * Contract verification (against upstream ai module commit pinned in
 * composer.json):
 *
 * `StreamedChatMessageIterator::reconstructChatOutput()` (lines 412-448 of
 * `web/modules/contrib/ai/src/OperationType/Chat/StreamedChatMessageIterator.php`)
 * ends with `$message->setTools($this->assembleToolCalls())`.
 *
 * `assembleToolCalls()` (lines 463-500 of the same file) walks every
 * `StreamedChatMessage::getTools()` entry and calls `$tool->toArray()` on
 * each one, then reads:
 *
 *   - $array_tool['id']                          (line 472)
 *   - $array_tool['function']['name']            (via $current_tool, lines 477/495)
 *   - $array_tool['function']['arguments']       (lines 475/486/493)
 *
 * It does not read the `type` key, but OpenAI clients expect it, so we set
 * it to the string `'function'` defensively.
 *
 * Bedrock-shape providers generate their tool calls as plain arrays — we
 * wrap them in this object so the contract holds. The shape produced by
 * {@see self::toArray()} matches the keys above exactly.
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
