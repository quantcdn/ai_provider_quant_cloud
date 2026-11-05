<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_quant_cloud\Unit\Service;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_provider_quant_cloud\Service\AuthService;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the OAuth refresh flow in AuthService.
 *
 * The portal rotates the refresh token on every use and revokes the whole
 * chain when an old token is replayed. These tests pin the rules that keep
 * two workers from sending the same refresh token.
 */
#[Group('ai_provider_quant_cloud')]
class AuthServiceTest extends UnitTestCase {

  /**
   * Persisted token values, as other processes would see them.
   *
   * @var array<string,string>
   */
  protected array $stored = [];

  /**
   * Per-request cached Key values (what getKeyValue() returns unless reset).
   *
   * @var array<string,string>
   */
  protected array $cached = [];

  /**
   * Persisted state values.
   *
   * @var array<string,mixed>
   */
  protected array $stateValues = [];

  /**
   * Ordered record of side effects.
   *
   * @var string[]
   */
  protected array $events = [];

  /**
   * Refresh tokens sent to the portal.
   *
   * @var string[]
   */
  protected array $sentRefreshTokens = [];

  /**
   * Logged messages with placeholders replaced.
   *
   * @var string[]
   */
  protected array $logs = [];

  /**
   * Settings values.
   *
   * @var array<string,mixed>
   */
  protected array $settings = [];

  /**
   * Answers to lock->acquire(), in call order.
   *
   * @var bool[]
   */
  protected array $acquireResults = [];

  /**
   * Called inside lock->acquire() when it succeeds.
   *
   * @var callable|null
   */
  protected $onAcquire = NULL;

  /**
   * Called inside lock->wait().
   *
   * @var callable|null
   */
  protected $onWait = NULL;

  /**
   * Builds the HTTP response for a refresh call.
   *
   * @var callable|null
   */
  protected $httpHandler = NULL;

  /**
   * Key entities that exist, by ID.
   *
   * @var array<string,bool>
   */
  protected array $keyExists = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->stored = [
      AuthService::ACCESS_KEY_ID => 'access-old',
      AuthService::REFRESH_KEY_ID => 'refresh-old',
    ];
    $this->cached = $this->stored;
    $this->keyExists = [
      AuthService::ACCESS_KEY_ID => TRUE,
      AuthService::REFRESH_KEY_ID => TRUE,
    ];
    $this->stateValues = [AuthService::EXPIRES_AT_STATE => time() - 10];
    $this->settings = [
      'auth.method' => 'oauth',
      'auth.access_token_key' => AuthService::ACCESS_KEY_ID,
      'auth.refresh_token_key' => AuthService::REFRESH_KEY_ID,
      'platform' => 'quantgov',
    ];
    $this->acquireResults = [TRUE];
  }

  /**
   * After the wait, a lock that is still held means no refresh is sent.
   */
  public function testNoRefreshWhenLockNotAcquiredAfterWait(): void {
    $this->acquireResults = [FALSE, FALSE];

    $token = $this->service()->getValidAccessToken();

    $this->assertNull($token);
    $this->assertSame([], $this->sentRefreshTokens);
    $this->assertNotContains('release', $this->events);
  }

  /**
   * Without the lock, a token that is still valid for a moment is used.
   */
  public function testNoLockReturnsStoredTokenThatIsNotExpiredYet(): void {
    $this->acquireResults = [FALSE, FALSE];
    $this->stateValues[AuthService::EXPIRES_AT_STATE] = time() + 30;

    $token = $this->service()->getValidAccessToken();

    $this->assertSame('access-old', $token);
    $this->assertSame([], $this->sentRefreshTokens);
    $this->assertNotContains('release', $this->events);
  }

  /**
   * A peer that finished during the wait supplies the token.
   */
  public function testPeerRefreshDuringWaitIsUsed(): void {
    $this->acquireResults = [FALSE];
    $this->onWait = function (): void {
      $this->stored[AuthService::ACCESS_KEY_ID] = 'access-peer';
      $this->stateValues[AuthService::EXPIRES_AT_STATE] = time() + 3600;
    };

    $token = $this->service()->getValidAccessToken();

    $this->assertSame('access-peer', $token);
    $this->assertSame([], $this->sentRefreshTokens);
  }

  /**
   * The refresh token is read from fresh storage inside the lock.
   */
  public function testRefreshTokenIsReadInsideLock(): void {
    // A peer rotates the token after this request first read it, and
    // before this request gets the lock. The access token is still expiring
    // so that the refresh goes ahead. The per-request Key cache still holds
    // the old value.
    $this->onAcquire = function (): void {
      $this->stored[AuthService::REFRESH_KEY_ID] = 'refresh-peer';
    };
    $this->httpHandler = fn() => $this->tokenResponse('access-new', 'refresh-new');

    $token = $this->service()->getValidAccessToken();

    $this->assertSame('access-new', $token);
    $this->assertSame(['refresh-peer'], $this->sentRefreshTokens);
  }

  /**
   * A "just rotated" answer reloads the tokens that the peer stored.
   */
  public function testJustRotatedReloadsStoredTokens(): void {
    $this->httpHandler = function (): Response {
      $this->stored[AuthService::ACCESS_KEY_ID] = 'access-peer';
      $this->stateValues[AuthService::EXPIRES_AT_STATE] = time() + 3600;
      return $this->errorResponse('Refresh token was just rotated; reload your stored tokens');
    };

    $token = $this->service()->getValidAccessToken();

    $this->assertSame('access-peer', $token);
    $this->assertNotContains('save:' . AuthService::ACCESS_KEY_ID, $this->events);
    $this->assertContains('release', $this->events);
  }

  /**
   * A rejected refresh keeps the stored tokens and logs no secret.
   */
  public function testFailedRefreshKeepsTokensAndLogsNoSecret(): void {
    $this->httpHandler = fn() => $this->errorResponse('Refresh token revoked');

    $token = $this->service()->getValidAccessToken();

    $this->assertNull($token);
    $this->assertSame('refresh-old', $this->stored[AuthService::REFRESH_KEY_ID]);
    $this->assertSame('access-old', $this->stored[AuthService::ACCESS_KEY_ID]);
    $this->assertArrayHasKey(AuthService::EXPIRES_AT_STATE, $this->stateValues);
    $this->assertSame(['release'], array_values(array_filter(
      $this->events,
      static fn(string $event) => !str_starts_with($event, 'acquire'),
    )));
    $this->assertNotEmpty($this->logs);
    foreach ($this->logs as $message) {
      $this->assertStringNotContainsString('refresh-old', $message);
      $this->assertStringNotContainsString('access-old', $message);
    }
  }

  /**
   * A refresh saves the rotated tokens before it releases the lock.
   */
  public function testRotatedTokensAreSavedBeforeLockRelease(): void {
    $this->httpHandler = fn() => $this->tokenResponse('access-new', 'refresh-new');

    $token = $this->service()->getValidAccessToken();

    $this->assertSame('access-new', $token);
    $this->assertSame('refresh-new', $this->stored[AuthService::REFRESH_KEY_ID]);
    $this->assertSame('access-new', $this->stored[AuthService::ACCESS_KEY_ID]);
    $this->assertSame([
      'acquire',
      'save:' . AuthService::REFRESH_KEY_ID,
      'save:' . AuthService::ACCESS_KEY_ID,
      'state:set',
      'config:save',
      'release',
    ], $this->events);
  }

  /**
   * A token that is not expiring is returned without the lock.
   */
  public function testValidTokenNeedsNoLock(): void {
    $this->stateValues[AuthService::EXPIRES_AT_STATE] = time() + 3600;

    $this->assertSame('access-old', $this->service()->getValidAccessToken());
    $this->assertSame([], $this->events);
  }

  /**
   * Manual mode never sends a refresh token.
   */
  public function testManualModeDoesNotRefresh(): void {
    $this->settings['auth.method'] = 'manual';

    $this->assertSame('access-old', $this->service()->getValidAccessToken());
    $this->assertSame([], $this->sentRefreshTokens);
  }

  /**
   * WriteKey updates an existing Key in place and never deletes it.
   */
  public function testWriteKeyUpdatesExistingKeyInPlace(): void {
    $this->service()->persistTokens([
      'access_token' => 'access-new',
      'refresh_token' => 'refresh-new',
      'expires_in' => 3600,
    ]);

    $this->assertSame('access-new', $this->stored[AuthService::ACCESS_KEY_ID]);
    $this->assertNotContains('delete', $this->events);
    $this->assertNotContains('create', $this->events);
  }

  /**
   * WriteKey creates the Key only when it is missing.
   */
  public function testWriteKeyCreatesMissingKey(): void {
    $this->keyExists[AuthService::REFRESH_KEY_ID] = FALSE;

    $this->service()->persistTokens([
      'access_token' => 'access-new',
      'refresh_token' => 'refresh-new',
      'expires_in' => 3600,
    ]);

    $this->assertSame(1, count(array_keys($this->events, 'create', TRUE)));
    $this->assertNotContains('delete', $this->events);
  }

  /**
   * Build the service under test against the fake world.
   */
  protected function service(): AuthService {
    return new AuthService(
      $this->httpClient(),
      $this->configFactory(),
      $this->loggerFactory(),
      $this->keyRepository(),
      $this->entityTypeManager(),
      $this->state(),
      $this->lock(),
    );
  }

  /**
   * A 200 token response.
   */
  protected function tokenResponse(string $access, string $refresh): Response {
    return new Response(200, [], (string) json_encode([
      'access_token' => $access,
      'refresh_token' => $refresh,
      'expires_in' => 3600,
    ]));
  }

  /**
   * A 400 invalid_grant response.
   */
  protected function errorResponse(string $description): Response {
    return new Response(400, [], (string) json_encode([
      'error' => 'invalid_grant',
      'error_description' => $description,
    ]));
  }

  /**
   * HTTP client that records sent refresh tokens.
   */
  protected function httpClient(): ClientInterface {
    $client = $this->createMock(Client::class);
    $client->method('post')->willReturnCallback(function (string $url, array $options): Response {
      $this->sentRefreshTokens[] = $options['form_params']['refresh_token'];
      $handler = $this->httpHandler ?? fn() => $this->errorResponse('unexpected');
      return $handler();
    });
    return $client;
  }

  /**
   * Config factory that reads $this->settings.
   */
  protected function configFactory(): ConfigFactoryInterface {
    $immutable = $this->createMock(ImmutableConfig::class);
    $immutable->method('get')->willReturnCallback(fn(string $name) => $this->settings[$name] ?? NULL);

    $editable = $this->createMock(Config::class);
    $editable->method('set')->willReturnCallback(function (string $name, $value) use (&$editable) {
      $this->settings[$name] = $value;
      return $editable;
    });
    $editable->method('save')->willReturnCallback(function () use (&$editable) {
      $this->events[] = 'config:save';
      return $editable;
    });

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($immutable);
    $factory->method('getEditable')->willReturn($editable);
    return $factory;
  }

  /**
   * Logger factory that records interpolated messages.
   */
  protected function loggerFactory(): LoggerChannelFactoryInterface {
    $channel = $this->createMock(LoggerChannelInterface::class);
    $record = function ($message, array $context = []): void {
      $this->logs[] = strtr((string) $message, array_map('strval', $context));
    };
    foreach (['error', 'warning', 'notice', 'critical', 'info'] as $level) {
      $channel->method($level)->willReturnCallback($record);
    }
    $channel->method('log')->willReturnCallback(fn($level, $message, array $context = []) => $record($message, $context));

    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($channel);
    return $factory;
  }

  /**
   * A Key entity mock backed by $this->stored and $this->cached.
   */
  protected function key(string $id): KeyInterface {
    $key = $this->createMock(KeyInterface::class);
    $key->method('getKeyValue')->willReturnCallback(function ($reset = FALSE) use ($id) {
      if ($reset || !array_key_exists($id, $this->cached)) {
        $this->cached[$id] = $this->stored[$id] ?? '';
      }
      return $this->cached[$id];
    });
    $key->method('setKeyValue')->willReturnCallback(function ($value) use ($id): void {
      $this->cached[$id] = $value;
    });
    $key->method('save')->willReturnCallback(function () use ($id) {
      $this->stored[$id] = $this->cached[$id];
      $this->events[] = 'save:' . $id;
      return 2;
    });
    $key->method('delete')->willReturnCallback(function (): void {
      $this->events[] = 'delete';
    });
    return $key;
  }

  /**
   * Key repository over the fake Key entities.
   */
  protected function keyRepository(): KeyRepositoryInterface {
    $repository = $this->createMock(KeyRepositoryInterface::class);
    $repository->method('getKey')->willReturnCallback(
      fn(string $id) => !empty($this->keyExists[$id]) ? $this->key($id) : NULL,
    );
    return $repository;
  }

  /**
   * Entity type manager with a fake Key storage.
   */
  protected function entityTypeManager(): EntityTypeManagerInterface {
    $storage = $this->createMock(ConfigEntityStorageInterface::class);
    $storage->method('load')->willReturnCallback(
      fn(string $id) => !empty($this->keyExists[$id]) ? $this->key($id) : NULL,
    );
    $storage->method('create')->willReturnCallback(function (array $values) {
      $this->events[] = 'create';
      $id = $values['id'];
      $this->keyExists[$id] = TRUE;
      $this->cached[$id] = $values['key_provider_settings']['key_value'];
      return $this->key($id);
    });

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('key')->willReturn($storage);
    return $manager;
  }

  /**
   * State service over $this->stateValues.
   */
  protected function state(): StateInterface {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(fn(string $name) => $this->stateValues[$name] ?? NULL);
    $state->method('set')->willReturnCallback(function (string $name, $value): void {
      $this->stateValues[$name] = $value;
      $this->events[] = 'state:set';
    });
    $state->method('delete')->willReturnCallback(function (string $name): void {
      unset($this->stateValues[$name]);
      $this->events[] = 'state:delete';
    });
    return $state;
  }

  /**
   * Lock backend driven by $this->acquireResults.
   */
  protected function lock(): LockBackendInterface {
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturnCallback(function (): bool {
      $acquired = array_shift($this->acquireResults) ?? FALSE;
      if ($acquired) {
        $this->events[] = 'acquire';
        if ($this->onAcquire) {
          ($this->onAcquire)();
        }
      }
      return $acquired;
    });
    $lock->method('wait')->willReturnCallback(function (): bool {
      if ($this->onWait) {
        ($this->onWait)();
      }
      return FALSE;
    });
    $lock->method('release')->willReturnCallback(function (): void {
      $this->events[] = 'release';
    });
    return $lock;
  }

}
