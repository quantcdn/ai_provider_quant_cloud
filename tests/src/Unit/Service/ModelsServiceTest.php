<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_quant_cloud\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\ai_provider_quant_cloud\Service\ModelsService;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Tests ModelsService::getMaxOutputTokens().
 *
 * The HTTP clients call this helper to clamp outgoing maxTokens; getting
 * its NULL semantics right is what keeps the clamp safe when a model
 * is unknown or its cap is missing/zero.
 */
#[Group('ai_provider_quant_cloud')]
class ModelsServiceTest extends UnitTestCase {

  /**
   * Build a ModelsService whose getModels() returns a canned list.
   *
   * @param array<int,array<string,mixed>>|\Throwable $models
   *   Either a fixed model list or an exception to throw from getModels().
   */
  protected function service(array|\Throwable $models): TestableModelsService {
    $reflection = new \ReflectionClass(TestableModelsService::class);
    /** @var TestableModelsService $service */
    $service = $reflection->newInstanceWithoutConstructor();

    $logger_property = (new \ReflectionObject($service))
      ->getProperty('logger');
    $logger_property->setAccessible(TRUE);
    $logger_property->setValue($service, new NullLogger());

    $service->setStubModels($models);
    return $service;
  }

  /**
   * Returns the model's maxOutputTokens when present and positive.
   */
  public function testReturnsPositiveCap(): void {
    $service = $this->service([
      ['id' => 'amazon.nova-lite-v1:0', 'maxOutputTokens' => 5000],
      ['id' => 'anthropic.claude', 'maxOutputTokens' => 8192],
    ]);

    $this->assertSame(
      5000,
      $service->getMaxOutputTokens('amazon.nova-lite-v1:0'),
    );
    $this->assertSame(
      8192,
      $service->getMaxOutputTokens('anthropic.claude'),
    );
  }

  /**
   * Returns NULL when the model is in the list but has a zero/missing cap.
   *
   * Several fallback embeddings/image models report `maxOutputTokens: 0`;
   * NULL signals "do not clamp" rather than "clamp to zero".
   */
  public function testReturnsNullForZeroCap(): void {
    $service = $this->service([
      ['id' => 'titan-embeddings', 'maxOutputTokens' => 0],
      ['id' => 'no-cap-field'],
    ]);

    $this->assertNull($service->getMaxOutputTokens('titan-embeddings'));
    $this->assertNull($service->getMaxOutputTokens('no-cap-field'));
  }

  /**
   * Returns NULL when the requested model isn't in the list.
   */
  public function testReturnsNullForUnknownModel(): void {
    $service = $this->service([
      ['id' => 'amazon.nova-lite-v1:0', 'maxOutputTokens' => 5000],
    ]);

    $this->assertNull($service->getMaxOutputTokens('not-here'));
  }

  /**
   * Returns NULL when the upstream getModels() call throws.
   *
   * Defensive path used when the dashboard client itself is misconfigured
   * (e.g. missing organisation id) so callers always get the safe
   * "unknown — pass through" answer.
   */
  public function testReturnsNullWhenGetModelsThrows(): void {
    $service = $this->service(new \RuntimeException('boom'));

    $this->assertNull($service->getMaxOutputTokens('amazon.nova-lite-v1:0'));
  }

}

/**
 * Test double that lets tests inject a canned model list or exception.
 */
class TestableModelsService extends ModelsService {

  /**
   * Canned models list, or an exception to throw from getModels().
   *
   * @var array<int,array<string,mixed>>|\Throwable
   */
  private array|\Throwable $stub = [];

  /**
   * Set the canned response getModels() should return (or throw).
   */
  public function setStubModels(array|\Throwable $stub): void {
    $this->stub = $stub;
  }

  /**
   * {@inheritdoc}
   */
  public function getModels(?string $feature = NULL, bool $bypass_cache = FALSE): array {
    if ($this->stub instanceof \Throwable) {
      throw $this->stub;
    }
    return $this->stub;
  }

}
