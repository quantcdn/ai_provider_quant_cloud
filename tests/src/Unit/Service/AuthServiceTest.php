<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_quant_cloud\Unit\Service;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueMemoryFactory;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_provider_quant_cloud\Service\AuthService;
use Drupal\ai_provider_quant_cloud\Service\OAuthTokenStore;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the OAuth refresh flow in AuthService.
 *
 * The portal rotates the refresh token on every use and revokes the whole
 * chain when an old token is replayed. These tests pin the rules that keep
 * two workers, or two copies of one database, from sending the same
 * refresh token.
 */
#[Group('ai_provider_quant_cloud')]
class AuthServiceTest extends UnitTestCase {

  /**
   * The in-memory key_value factory shared by every service instance.
   */
  protected KeyValueMemoryFactory $keyValue;

  /**
   * The token store, as other processes would see it.
   */
  protected OAuthTokenStore $store;

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
   * Settings values (also system.site:uuid as 'uuid').
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
   * Values passed to Key storage create(), by ID.
   *
   * @var array<string,array>
   */
  protected array $createdKeys = [];

  /**
   * The stored refresh token at the moment the lock was released.
   */
  protected ?string $refreshAtRelease = NULL;

  /**
   * Seconds that the service paused, in order.
   *
   * @var int[]
   */
  protected array $pauses = [];

  /**
   * Queued HTTP answers (Response or Throwable), used before $httpHandler.
   *
   * @var array
   */
  protected array $httpQueue = [];

  /**
   * Whether the module access Key still holds a value in config.
   */
  protected bool $unmigratedAccessKey = FALSE;

  /**
   * Whether the service runs as CLI (cron, drush) or as a web request.
   */
  protected bool $cli = TRUE;

  /**
   * Whether the database reports an open transaction.
   */
  protected bool $inTransaction = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->keyValue = new KeyValueMemoryFactory();
    $this->store = new OAuthTokenStore($this->keyValue);
    $this->store->saveTokens([
      'access_token' => 'access-old',
      'refresh_token' => 'refresh-old',
      'expires_in' => 7776000,
    ]);
    $this->setExpiresIn(-10);
    $this->settings = [
      'auth.method' => 'oauth',
      'auth.access_token_key' => AuthService::ACCESS_KEY_ID,
      'auth.refresh_token_key' => AuthService::REFRESH_KEY_ID,
      'platform' => 'quantgov',
      'uuid' => 'site-uuid',
    ];
    $this->acquireResults = [TRUE];
    new Settings(['hash_salt' => 'salt-prod']);
  }

  /**
   * Store the tokens that a peer process saved.
   */
  protected function storePeerTokens(): void {
    $this->store->saveTokens([
      'access_token' => 'access-peer',
      'refresh_token' => 'refresh-peer',
      'expires_in' => 3600,
    ]);
  }

  /**
   * Set the stored expiry relative to now.
   */
  protected function setExpiresIn(int $seconds): void {
    $this->store->set(OAuthTokenStore::EXPIRES_AT, time() + $seconds);
  }

  /**
   * After the wait, a lock that is still held means no refresh is sent.
   */
  public function testNoRefreshWhenLockNotAcquiredAfterWait(): void {
    $this->acquireResults = [FALSE, FALSE];

    $this->assertNull($this->service()->getValidAccessToken());
    $this->assertSame([], $this->sentRefreshTokens);
    $this->assertNotContains('release', $this->events);
  }

  /**
   * Without the lock, a token that is still valid for a moment is used.
   */
  public function testNoLockReturnsStoredTokenThatIsNotExpiredYet(): void {
    $this->acquireResults = [FALSE, FALSE];
    $this->setExpiresIn(30);

    $this->assertSame('access-old', $this->service()->getValidAccessToken());
    $this->assertSame([], $this->sentRefreshTokens);
  }

  /**
   * A peer that finished during the wait supplies the token.
   */
  public function testPeerRefreshDuringWaitIsUsed(): void {
    $this->acquireResults = [FALSE];
    $this->onWait = function (): void {
      $this->storePeerTokens();
    };

    $this->assertSame('access-peer', $this->service()->getValidAccessToken());
    $this->assertSame([], $this->sentRefreshTokens);
  }

  /**
   * A refresh token that changed before the lock means a peer refreshed.
   */
  public function testChangedRefreshTokenInsideLockSkipsRefresh(): void {
    $this->onAcquire = function (): void {
      $this->storePeerTokens();
    };
    $this->httpHandler = fn() => $this->tokenResponse('access-new', 'refresh-new');

    $this->assertSame('access-peer', $this->service()->getValidAccessToken());
    $this->assertSame([], $this->sentRefreshTokens);
    $this->assertContains('release', $this->events);
  }

  /**
   * The refresh token is read from storage after the lock is acquired.
   */
  public function testRefreshUsesStoredRefreshToken(): void {
    $this->httpHandler = fn() => $this->tokenResponse('access-new', 'refresh-new');

    $this->assertSame('access-new', $this->service()->getValidAccessToken());
    $this->assertSame(['refresh-old'], $this->sentRefreshTokens);
  }

  /**
   * A "just rotated" answer reloads the tokens that the peer stored.
   */
  public function testJustRotatedReloadsStoredTokens(): void {
    $this->httpHandler = function (): Response {
      $this->storePeerTokens();
      return $this->errorResponse('invalid_grant', 'Refresh token was just rotated; reload your stored tokens');
    };

    $this->assertSame('access-peer', $this->service()->getValidAccessToken());
    $this->assertNull($this->store->get(OAuthTokenStore::REFUSAL));
    $this->assertContains('release', $this->events);
  }

  /**
   * A final refusal is recorded, logged once and stops further refreshes.
   */
  public function testRefusalIsRecordedAndStopsRefreshes(): void {
    $this->httpHandler = fn() => $this->errorResponse('invalid_grant', 'Refresh token revoked');

    $this->assertNull($this->service()->getValidAccessToken());
    $this->assertNull($this->service()->getValidAccessToken());

    $this->assertSame(['refresh-old'], $this->sentRefreshTokens);
    $this->assertSame('refresh-old', $this->store->get(OAuthTokenStore::REFRESH_TOKEN));
    $this->assertSame('access-old', $this->store->get(OAuthTokenStore::ACCESS_TOKEN));
    $this->assertSame(AuthService::RECONNECT_REFUSED, $this->service()->getReconnectReason());
    $this->assertSame('Refresh token revoked', $this->service()->getReconnectMessage()->getArguments()['@description']);
    $this->assertCount(1, array_filter($this->logs, static fn($m) => str_contains($m, 'refused the OAuth token refresh')));
    foreach ($this->logs as $message) {
      $this->assertStringNotContainsString('refresh-old', $message);
      $this->assertStringNotContainsString('access-old', $message);
    }
  }

  /**
   * An invalid_client answer is a final refusal too.
   */
  public function testInvalidClientIsRefusal(): void {
    $this->httpHandler = fn() => $this->errorResponse('invalid_client', 'PKCE required');

    $this->service()->getValidAccessToken();

    $this->assertSame(['error' => 'invalid_client', 'description' => 'PKCE required'], $this->store->get(OAuthTokenStore::REFUSAL));
  }

  /**
   * Connecting again clears a recorded refusal.
   */
  public function testConnectClearsRefusal(): void {
    $this->store->set(OAuthTokenStore::REFUSAL, ['error' => 'invalid_grant', 'description' => 'x']);

    $this->service()->persistTokens(['access_token' => 'a2', 'refresh_token' => 'r2', 'expires_in' => 3600]);

    $this->assertNull($this->store->get(OAuthTokenStore::REFUSAL));
    $this->assertNull($this->service()->getReconnectReason());
  }

  /**
   * An unknown refresh outcome keeps the tokens and uses the valid token.
   */
  public function testUnknownOutcomeKeepsTokensAndReturnsValidToken(): void {
    $this->setExpiresIn(30);
    $this->httpQueue = [$this->timeout(), $this->timeout()];

    $this->assertSame('access-old', $this->service()->getValidAccessToken());
    $this->assertSame('refresh-old', $this->store->get(OAuthTokenStore::REFRESH_TOKEN));
    $this->assertNull($this->store->get(OAuthTokenStore::REFUSAL));
    $this->assertSame(['acquire', 'keepRunning', 'http', 'http', 'release'], $this->events);
    $this->assertSame([15], $this->pauses);
  }

  /**
   * A timeout followed by a 200 recovers the lost rotation.
   */
  public function testRecoveryRetryPersistsRecoveredPair(): void {
    $this->httpQueue = [$this->timeout(), $this->tokenResponse('access-new', 'refresh-new')];

    $this->assertSame('access-new', $this->service()->getValidAccessToken());

    $this->assertSame(['refresh-old', 'refresh-old'], $this->sentRefreshTokens);
    $this->assertSame([15], $this->pauses);
    $this->assertSame('refresh-new', $this->refreshAtRelease);
    $this->assertNull($this->store->get(OAuthTokenStore::RETRY_AFTER));
  }

  /**
   * A 5xx and an unreadable 200 are unknown outcomes and get the retry.
   */
  public function testServerErrorAndUnreadable200AreRetried(): void {
    $this->httpQueue = [new Response(503), new Response(200, [], 'not json')];

    $this->assertNull($this->service()->getValidAccessToken());

    $this->assertCount(2, $this->sentRefreshTokens);
    $this->assertSame('refresh-old', $this->store->get(OAuthTokenStore::REFRESH_TOKEN));
  }

  /**
   * A refusal on the retry is recorded as usual.
   */
  public function testRefusalOnRetryIsRecorded(): void {
    $this->httpQueue = [new Response(502), $this->errorResponse('invalid_grant', 'Refresh token not found')];

    $this->service()->getValidAccessToken();

    $this->assertSame('invalid_grant', $this->store->get(OAuthTokenStore::REFUSAL)['error']);
    $this->assertNull($this->store->get(OAuthTokenStore::RETRY_AFTER));
  }

  /**
   * A known non-final error is not retried.
   */
  public function testOtherClientErrorIsNotRetried(): void {
    $this->httpQueue = [$this->errorResponse('invalid_request', 'bad')];

    $this->service()->getValidAccessToken();

    $this->assertCount(1, $this->sentRefreshTokens);
    $this->assertSame([], $this->pauses);
  }

  /**
   * After two unknown outcomes, web requests and cron back off.
   */
  public function testBackOffAfterTwoUnknownOutcomes(): void {
    $this->setExpiresIn(30);
    $this->acquireResults = [TRUE, TRUE];
    $this->httpQueue = [new Response(503), new Response(503)];
    $service = $this->service();

    $this->assertSame('access-old', $service->getValidAccessToken());
    $this->assertGreaterThanOrEqual(time() + 59, (int) $this->store->get(OAuthTokenStore::RETRY_AFTER));
    $this->assertGreaterThanOrEqual(time() + 599, (int) $this->store->get(OAuthTokenStore::CRON_RETRY_AFTER));

    $this->assertSame('access-old', $service->getValidAccessToken());
    $service->refreshFromCron();
    $this->assertCount(2, $this->sentRefreshTokens);

    // After the cron backoff, cron tries again; web requests may still wait.
    $this->store->set(OAuthTokenStore::CRON_RETRY_AFTER, time() - 1);
    $this->httpQueue = [$this->tokenResponse('access-new', 'refresh-new')];
    $service->refreshFromCron();
    $this->assertCount(3, $this->sentRefreshTokens);
    $this->assertNull($this->store->get(OAuthTokenStore::RETRY_AFTER));
    $this->assertNull($this->store->get(OAuthTokenStore::PENDING));
  }

  /**
   * A web request does not sleep or retry; it leaves a pending marker.
   */
  public function testWebUnknownOutcomeLeavesPendingMarker(): void {
    $this->cli = FALSE;
    $this->setExpiresIn(30);
    $this->httpQueue = [$this->timeout()];

    $this->assertSame('access-old', $this->service()->getValidAccessToken());

    $this->assertCount(1, $this->sentRefreshTokens);
    $this->assertSame([], $this->pauses);
    $pending = $this->store->get(OAuthTokenStore::PENDING);
    $this->assertSame(hash('sha256', 'refresh-old'), $pending['token']);
    $this->assertSame(1, $pending['attempts']);
    $this->assertSame(['acquire', 'keepRunning', 'http', 'release'], $this->events);
  }

  /**
   * The next process retries a recent pending refresh at once.
   *
   * It ignores the backoff and the expiry, and it recovers the pair.
   */
  public function testNextProcessRecoversPendingRefresh(): void {
    $this->cli = FALSE;
    $this->setExpiresIn(30);
    $this->acquireResults = [TRUE, TRUE];
    $this->httpQueue = [$this->timeout(), $this->tokenResponse('access-new', 'refresh-new')];

    $this->service()->getValidAccessToken();
    $this->assertSame('access-new', $this->service()->getValidAccessToken());

    $this->assertSame(['refresh-old', 'refresh-old'], $this->sentRefreshTokens);
    $this->assertNull($this->store->get(OAuthTokenStore::PENDING));
  }

  /**
   * A marker left by a dead process is retried even before expiry.
   */
  public function testPendingMarkerFromDeadProcessIsRetried(): void {
    $this->setExpiresIn(3600);
    $this->setPending(time() - 60, 'refresh-old');
    $this->httpQueue = [$this->tokenResponse('access-new', 'refresh-new')];

    $this->assertSame('access-new', $this->service()->getValidAccessToken());
  }

  /**
   * An old marker, or one for another token, is not retried.
   */
  public function testStalePendingMarkerIsIgnored(): void {
    $this->setExpiresIn(3600);
    $this->setPending(time() - 300, 'refresh-old');
    $this->assertSame('access-old', $this->service()->getValidAccessToken());

    $this->setPending(time(), 'other');
    $this->assertSame('access-old', $this->service()->getValidAccessToken());
    $this->assertSame([], $this->sentRefreshTokens);
  }

  /**
   * The lock outlives a refresh, the recovery pause and the retry.
   */
  public function testLockLifetimeCoversRecovery(): void {
    $class = new \ReflectionClass(AuthService::class);
    $needed = 2 * $class->getConstant('REFRESH_HTTP_TIMEOUT') + $class->getConstant('RECOVERY_DELAY');

    $this->assertGreaterThan($needed, $class->getConstant('REFRESH_LOCK_TIMEOUT'));
  }

  /**
   * Store a pending marker for one unanswered send of a refresh token.
   */
  protected function setPending(int $since, string $refresh_token): void {
    $this->store->set(OAuthTokenStore::PENDING, [
      'since' => $since,
      'token' => hash('sha256', $refresh_token),
      'attempts' => 1,
    ]);
  }

  /**
   * A transport timeout.
   */
  protected function timeout(): ConnectException {
    return new ConnectException('cURL error 28: Operation timed out', new Request('POST', 'https://dash.quantgov.cloud/oauth/token'));
  }

  /**
   * A refresh saves the rotated tokens before the lock is released.
   *
   * It writes no config.
   */
  public function testRotatedTokensAreSavedBeforeLockReleaseWithoutConfig(): void {
    $this->httpHandler = fn() => $this->tokenResponse('access-new', 'refresh-new');

    $this->assertSame('access-new', $this->service()->getValidAccessToken());
    $this->assertSame('refresh-new', $this->store->get(OAuthTokenStore::REFRESH_TOKEN));
    $this->assertSame('access-new', $this->store->get(OAuthTokenStore::ACCESS_TOKEN));
    $this->assertSame(['acquire', 'keepRunning', 'http', 'release'], $this->events);
    $this->assertSame('refresh-new', $this->refreshAtRelease);
  }

  /**
   * A token that is not expiring is returned without the lock.
   */
  public function testValidTokenNeedsNoLock(): void {
    $this->setExpiresIn(3600);

    $this->assertSame('access-old', $this->service()->getValidAccessToken());
    $this->assertSame([], $this->events);
  }

  /**
   * Manual mode reads the selected Key and never sends a refresh token.
   */
  public function testManualModeDoesNotRefresh(): void {
    $this->settings['auth.method'] = 'manual';
    $this->settings['auth.access_token_key'] = 'my_manual_key';

    $this->assertSame('manual-value', $this->service()->getValidAccessToken());
    $this->assertSame([], $this->sentRefreshTokens);
    $this->assertNull($this->service()->getReconnectReason());
  }

  /**
   * In an open transaction, a token that still works is not refreshed.
   */
  public function testNoRefreshInsideTransactionWhileTokenWorks(): void {
    $this->inTransaction = TRUE;
    $this->setExpiresIn(30);

    $this->assertSame('access-old', $this->service()->getValidAccessToken());
    $this->assertSame([], $this->sentRefreshTokens);
  }

  /**
   * In an open transaction, even an expired token is not refreshed.
   */
  public function testNoRefreshInsideTransactionWhenTokenExpired(): void {
    $this->inTransaction = TRUE;
    $this->httpHandler = fn() => $this->tokenResponse('access-new', 'refresh-new');

    $this->assertNull($this->service()->getValidAccessToken());
    $this->service()->refreshFromCron();
    $this->assertSame([], $this->sentRefreshTokens);
  }

  /**
   * Cron refreshes when fewer than 45 days remain, and not before.
   */
  public function testCronRefreshesWithin45Days(): void {
    $this->httpHandler = fn() => $this->tokenResponse('access-new', 'refresh-new');

    $this->setExpiresIn(46 * 86400);
    $this->service()->refreshFromCron();
    $this->assertSame([], $this->sentRefreshTokens);

    $this->setExpiresIn(44 * 86400);
    $this->service()->refreshFromCron();
    $this->assertSame(['refresh-old'], $this->sentRefreshTokens);
  }

  /**
   * For a short-lived token, cron refreshes in the second half of its life.
   */
  public function testCronMarginIsHalfOfShortLifetime(): void {
    $this->httpHandler = fn() => $this->tokenResponse('access-new', 'refresh-new');
    $this->store->set(OAuthTokenStore::LIFETIME, 3600);

    $this->setExpiresIn(2400);
    $this->service()->refreshFromCron();
    $this->assertSame([], $this->sentRefreshTokens);

    $this->setExpiresIn(1200);
    $this->service()->refreshFromCron();
    $this->assertSame(['refresh-old'], $this->sentRefreshTokens);
  }

  /**
   * After a 401, a newer stored token is offered once; the same is not.
   */
  public function testTokenAfterUnauthorized(): void {
    $service = $this->service();
    $this->assertNull($service->getTokenAfterUnauthorized('access-old'));

    $this->store->set(OAuthTokenStore::ACCESS_TOKEN, 'access-peer');
    $this->assertSame('access-peer', $service->getTokenAfterUnauthorized('access-old'));
  }

  /**
   * Tokens copied from another site are used but never refreshed.
   */
  public function testTokensFromAnotherSiteAreUsedButNotRefreshed(): void {
    $this->store->set(OAuthTokenStore::FINGERPRINT, $this->service()->getSiteFingerprint());
    // The copy has its own settings.php, so its fingerprint differs.
    new Settings(['hash_salt' => 'salt-staging']);
    $service = $this->service();

    $this->setExpiresIn(3600);
    $this->assertSame('access-old', $service->getValidAccessToken());

    $this->setExpiresIn(30);
    $this->assertSame('access-old', $service->getValidAccessToken());
    $this->assertSame('access-old', $service->getValidAccessToken());
    $this->service()->refreshFromCron();

    $this->assertSame([], $this->sentRefreshTokens);
    $this->assertSame(AuthService::RECONNECT_OTHER_SITE, $service->getReconnectReason());
    $this->assertCount(1, array_filter($this->logs, static fn($m) => str_contains($m, 'issued to another site')));
  }

  /**
   * Keeping the connection re-stamps the fingerprint, and refresh resumes.
   */
  public function testConfirmSameSiteResumesRefresh(): void {
    $this->store->set(OAuthTokenStore::FINGERPRINT, ['uuid' => 'another-site']);
    $this->httpHandler = fn() => $this->tokenResponse('access-new', 'refresh-new');
    $service = $this->service();
    $this->assertSame(AuthService::RECONNECT_OTHER_SITE, $service->getReconnectReason());

    $service->confirmSameSite();

    $this->assertNull($service->getReconnectReason());
    $this->assertSame('access-new', $service->getValidAccessToken());
  }

  /**
   * Tokens without a fingerprint keep working and get one on refresh.
   */
  public function testMissingFingerprintIsStampedOnRefresh(): void {
    $this->httpHandler = fn() => $this->tokenResponse('access-new', 'refresh-new');
    $service = $this->service();

    $this->assertSame('access-new', $service->getValidAccessToken());
    $this->assertSame($service->getSiteFingerprint(), $this->store->get(OAuthTokenStore::FINGERPRINT));
  }

  /**
   * The database host and port do not change the fingerprint; the name does.
   */
  public function testFingerprintIgnoresDatabaseHostAndPort(): void {
    $original = Database::getConnectionInfo('default');
    try {
      $first = $this->fingerprintWithDatabase(['host' => 'db1', 'port' => '3306', 'database' => 'prod']);
      $moved = $this->fingerprintWithDatabase(['host' => 'db2.internal', 'port' => '3307', 'database' => 'prod']);
      $copy = $this->fingerprintWithDatabase(['host' => 'db1', 'port' => '3306', 'database' => 'staging']);
    }
    finally {
      Database::removeConnection('default');
      if ($original) {
        Database::addConnectionInfo('default', 'default', $original['default']);
      }
    }

    $this->assertSame($first, $moved);
    $this->assertNotSame($first, $copy);
  }

  /**
   * The fingerprint with the given default database connection info.
   */
  protected function fingerprintWithDatabase(array $info): array {
    Database::removeConnection('default');
    Database::addConnectionInfo('default', 'default', $info + ['driver' => 'mysql', 'prefix' => '']);
    return $this->service()->getSiteFingerprint();
  }

  /**
   * The site ID setting replaces the automatic fingerprint parts.
   */
  public function testSiteIdSettingChangesFingerprint(): void {
    $automatic = $this->service()->getSiteFingerprint();
    new Settings(['hash_salt' => 'salt-prod', 'ai_provider_quant_cloud_site_id' => 'prod']);
    $prod = $this->service()->getSiteFingerprint();
    new Settings(['hash_salt' => 'salt-prod', 'ai_provider_quant_cloud_site_id' => 'staging']);

    $this->assertNotSame($automatic, $prod);
    $this->assertNotSame($prod, $this->service()->getSiteFingerprint());
  }

  /**
   * The legacy warning applies only to the module's own OAuth Key.
   */
  public function testLegacyTokenNeedsReconnectOnlyForModuleKey(): void {
    $this->store->delete([OAuthTokenStore::REFRESH_TOKEN]);
    $this->assertSame(AuthService::RECONNECT_LEGACY_TOKEN, $this->service()->getReconnectReason());

    $this->settings['auth.access_token_key'] = 'my_manual_key';
    $this->assertNull($this->service()->getReconnectReason());
  }

  /**
   * The mismatch names the component that differs, without its value.
   */
  public function testFingerprintDifferencesNameTheComponent(): void {
    $this->store->set(OAuthTokenStore::FINGERPRINT, $this->service()->getSiteFingerprint());
    new Settings(['hash_salt' => 'salt-staging']);

    $differences = $this->service()->getFingerprintDifferences();

    $this->assertSame(["\$settings['hash_salt']"], $differences);
    $this->assertStringNotContainsString('salt-staging', implode(' ', $differences));
  }

  /**
   * Hosting environment variables are part of the fingerprint.
   */
  public function testHostingEnvironmentVariableChangesFingerprint(): void {
    try {
      putenv('PLATFORM_ENVIRONMENT=main');
      $production = $this->service()->getSiteFingerprint();
      $this->store->set(OAuthTokenStore::FINGERPRINT, $production);
      putenv('PLATFORM_ENVIRONMENT=pr-12');
      $preview = $this->service()->getSiteFingerprint();
      $differences = $this->service()->getFingerprintDifferences();
    }
    finally {
      putenv('PLATFORM_ENVIRONMENT');
    }

    $this->assertNotSame($production, $preview);
    $this->assertSame(['environment variable PLATFORM_ENVIRONMENT'], $differences);
  }

  /**
   * Before update 10004 runs, the token is read from the old Key.
   */
  public function testUnmigratedKeyTokenIsUsedWithoutRefresh(): void {
    $this->store->clear();
    $this->unmigratedAccessKey = TRUE;
    $this->httpHandler = fn() => $this->tokenResponse('access-new', 'refresh-new');

    $this->assertSame('legacy-key-access', $this->service()->getValidAccessToken());
    $this->assertSame('legacy-key-access', $this->service()->getAccessToken());
    $this->service()->refreshFromCron();
    $this->assertSame([], $this->sentRefreshTokens);
  }

  /**
   * Connect stores the tokens and points the Keys at the store.
   */
  public function testPersistTokensCreatesStoreBackedKeys(): void {
    $this->service()->persistTokens([
      'access_token' => 'access-new',
      'refresh_token' => 'refresh-new',
      'expires_in' => 3600,
    ]);

    $this->assertSame('access-new', $this->store->get(OAuthTokenStore::ACCESS_TOKEN));
    $this->assertSame(['create:' . AuthService::ACCESS_KEY_ID, 'create:' . AuthService::REFRESH_KEY_ID, 'config:save'], $this->events);
    $this->assertSame(AuthService::KEY_PROVIDER_ID, $this->createdKeys[AuthService::ACCESS_KEY_ID]['key_provider']);
    $this->assertSame(['token' => 'refresh'], $this->createdKeys[AuthService::REFRESH_KEY_ID]['key_provider_settings']);
    $this->assertStringNotContainsString('access-new', serialize($this->createdKeys));
  }

  /**
   * Build the service under test against the fake world.
   */
  protected function service(): AuthService {
    $state = $this->createMock(StateInterface::class);
    $database = $this->createMock(Connection::class);
    $database->method('inTransaction')->willReturnCallback(fn() => $this->inTransaction);

    $service = new TestableAuthService(
      $this->httpClient(),
      $this->configFactory(),
      $this->loggerFactory(),
      $this->keyRepository(),
      $this->entityTypeManager(),
      $state,
      $this->lock(),
      new OAuthTokenStore($this->keyValue),
      $database,
    );
    $service->pauses = &$this->pauses;
    $service->events = &$this->events;
    $service->cli = $this->cli;
    return $service;
  }

  /**
   * A 200 token response.
   */
  protected function tokenResponse(string $access, string $refresh): Response {
    return new Response(200, [], (string) json_encode([
      'access_token' => $access,
      'refresh_token' => $refresh,
      'expires_in' => 7776000,
    ]));
  }

  /**
   * A 400 error response.
   */
  protected function errorResponse(string $error, string $description): Response {
    return new Response(400, [], (string) json_encode([
      'error' => $error,
      'error_description' => $description,
    ]));
  }

  /**
   * HTTP client that records sent refresh tokens.
   */
  protected function httpClient(): Client {
    $client = $this->createMock(Client::class);
    $client->method('post')->willReturnCallback(function (string $url, array $options): Response {
      $this->events[] = 'http';
      $this->sentRefreshTokens[] = $options['form_params']['refresh_token'];
      if ($this->httpQueue) {
        $next = array_shift($this->httpQueue);
        if ($next instanceof \Throwable) {
          throw $next;
        }
        return $next;
      }
      $handler = $this->httpHandler ?? fn() => $this->errorResponse('invalid_request', 'unexpected');
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
   * Key repository with one manual Key.
   */
  protected function keyRepository(): KeyRepositoryInterface {
    $manual = $this->createMock('Drupal\key\KeyInterface');
    $manual->method('getKeyValue')->willReturn('manual-value');

    $provider = $this->createMock('Drupal\key\Plugin\KeyProviderBase');
    $provider->method('getPluginId')->willReturn('config');
    $unmigrated = $this->createMock('Drupal\key\KeyInterface');
    $unmigrated->method('getKeyProvider')->willReturn($provider);
    $unmigrated->method('getKeyValue')->willReturn('legacy-key-access');

    $repository = $this->createMock(KeyRepositoryInterface::class);
    $repository->method('getKey')->willReturnCallback(function (string $id) use ($manual, $unmigrated) {
      if ($id === 'my_manual_key') {
        return $manual;
      }
      return $id === AuthService::ACCESS_KEY_ID && $this->unmigratedAccessKey ? $unmigrated : NULL;
    });
    return $repository;
  }

  /**
   * Entity type manager with a Key storage that records creates.
   */
  protected function entityTypeManager(): EntityTypeManagerInterface {
    $storage = $this->createMock(ConfigEntityStorageInterface::class);
    $storage->method('load')->willReturn(NULL);
    $storage->method('create')->willReturnCallback(function (array $values) {
      $this->events[] = 'create:' . $values['id'];
      $this->createdKeys[$values['id']] = $values;
      return $this->createMock('Drupal\key\KeyInterface');
    });

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('key')->willReturn($storage);
    return $manager;
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
      $this->refreshAtRelease = $this->store->get(OAuthTokenStore::REFRESH_TOKEN);
    });
    return $lock;
  }

}

/**
 * AuthService that records its pauses instead of sleeping.
 */
class TestableAuthService extends AuthService {

  /**
   * Seconds paused, in order.
   *
   * @var int[]
   */
  public array $pauses = [];

  /**
   * The test's event list.
   *
   * @var string[]
   */
  public array $events = [];

  /**
   * Whether to report a CLI process.
   */
  public bool $cli = TRUE;

  /**
   * {@inheritdoc}
   */
  protected function pause(int $seconds): void {
    $this->pauses[] = $seconds;
  }

  /**
   * {@inheritdoc}
   */
  protected function keepRunning(): void {
    $this->events[] = 'keepRunning';
  }

  /**
   * {@inheritdoc}
   */
  protected function isCli(): bool {
    return $this->cli;
  }

}
