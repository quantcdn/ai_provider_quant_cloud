<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_quant_cloud\Unit\Client;

use Drupal\Tests\UnitTestCase;
use Drupal\ai_provider_quant_cloud\Client\AuthRetryTrait;
use Drupal\ai_provider_quant_cloud\Service\AuthService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the single retry after a 401.
 */
#[Group('ai_provider_quant_cloud')]
class AuthRetryTraitTest extends UnitTestCase {

  /**
   * Authorization headers that reached the HTTP layer.
   *
   * @var string[]
   */
  protected array $sent = [];

  /**
   * Build a client that answers with the given responses.
   */
  protected function sender(array $responses, ?string $newer_token): object {
    $history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $this->sent = &$history;

    $auth = $this->createMock(AuthService::class);
    $auth->method('getTokenAfterUnauthorized')->willReturn($newer_token);

    return new class(new Client(['handler' => $stack]), $auth) {
      use AuthRetryTrait;

      public function __construct(
        protected Client $httpClient,
        protected AuthService $authService,
      ) {}

      /**
       * Expose the trait method.
       */
      public function send(): Response {
        return $this->sendWithAuthRetry('POST', 'https://example.com/api', [
          'headers' => ['Authorization' => 'Bearer stale'],
        ]);
      }

    };
  }

  /**
   * A 401 is retried once with the newer stored token.
   */
  public function testRetriesOnceWithNewerToken(): void {
    $sender = $this->sender([new Response(401), new Response(200)], 'fresh');

    $this->assertSame(200, $sender->send()->getStatusCode());
    $headers = array_map(static fn($t) => $t['request']->getHeaderLine('Authorization'), $this->sent);
    $this->assertSame(['Bearer stale', 'Bearer fresh'], $headers);
  }

  /**
   * Without a newer token, the 401 is not retried.
   */
  public function testNoRetryWithoutNewerToken(): void {
    $sender = $this->sender([new Response(401), new Response(200)], NULL);

    $this->expectException(ClientException::class);
    try {
      $sender->send();
    }
    finally {
      $this->assertCount(1, $this->sent);
    }
  }

  /**
   * A second 401 is not retried again.
   */
  public function testRetriesOnlyOnce(): void {
    $sender = $this->sender([new Response(401), new Response(401), new Response(200)], 'fresh');

    $this->expectException(ClientException::class);
    try {
      $sender->send();
    }
    finally {
      $this->assertCount(2, $this->sent);
    }
  }

}
