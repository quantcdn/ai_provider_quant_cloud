<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_quant_cloud\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\ai_provider_quant_cloud\Form\KeepConnectionForm;
use Drupal\ai_provider_quant_cloud\Service\AuthService;
use Drupal\ai_provider_quant_cloud\Service\OAuthTokenStore;
use Drupal\key\Entity\Key;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests token storage, the 1.1.0 storage update and the status report.
 */
#[Group('ai_provider_quant_cloud')]
#[RunTestsInSeparateProcesses]
class AuthServiceKeyStorageTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'key', 'ai', 'ai_provider_quant_cloud'];

  /**
   * Requests sent through the mock HTTP client.
   *
   * @var array
   */
  protected array $history = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->installEntitySchema('user');
    $this->container->get('module_handler')->loadInclude('ai_provider_quant_cloud', 'install');
  }

  /**
   * Build the service, optionally with canned HTTP responses.
   *
   * @param \Psr\Http\Message\ResponseInterface[] $responses
   *   Responses for the mock HTTP client.
   */
  protected function service(array $responses = []): AuthService {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));
    return new AuthService(
      new Client(['handler' => $stack]),
      $this->container->get('config.factory'),
      $this->container->get('logger.factory'),
      $this->container->get('key.repository'),
      $this->container->get('entity_type.manager'),
      $this->container->get('state'),
      $this->container->get('lock'),
      $this->container->get('ai_provider_quant_cloud.token_store'),
      $this->container->get('database'),
    );
  }

  /**
   * The raw active config record, as config export would write it.
   */
  protected function rawConfig(string $name): string {
    return serialize($this->container->get('config.storage')->read($name));
  }

  /**
   * A token response body.
   */
  protected function tokenResponse(string $access, string $refresh, int $expires_in = 7776000): Response {
    return new Response(200, [], (string) json_encode([
      'access_token' => $access,
      'refresh_token' => $refresh,
      'expires_in' => $expires_in,
    ]));
  }

  /**
   * Connect stores the token values outside config.
   */
  public function testConnectStoresTokensOutsideConfig(): void {
    $this->service()->persistTokens([
      'access_token' => 'access-1',
      'refresh_token' => 'refresh-1',
      'expires_in' => 3600,
    ]);

    foreach ([AuthService::ACCESS_KEY_ID, AuthService::REFRESH_KEY_ID] as $key_id) {
      $raw = $this->rawConfig('key.key.' . $key_id);
      $this->assertStringContainsString(AuthService::KEY_PROVIDER_ID, $raw);
      $this->assertStringNotContainsString('access-1', $raw);
      $this->assertStringNotContainsString('refresh-1', $raw);
    }
    $this->assertSame('access-1', Key::load(AuthService::ACCESS_KEY_ID)->getKeyValue(TRUE));
    $this->assertSame('refresh-1', Key::load(AuthService::REFRESH_KEY_ID)->getKeyValue(TRUE));
    $this->assertSame('access-1', $this->service()->getValidAccessToken());
  }

  /**
   * A config import of an old Key export does not change the tokens.
   */
  public function testConfigImportDoesNotTouchTokenValues(): void {
    $this->service()->persistTokens([
      'access_token' => 'access-1',
      'refresh_token' => 'refresh-1',
      'expires_in' => 30,
    ]);

    // What "drush cim" of an export from release 1.0.0 writes back.
    foreach ([AuthService::ACCESS_KEY_ID => 'stale-access', AuthService::REFRESH_KEY_ID => 'stale-refresh'] as $key_id => $value) {
      $record = $this->container->get('config.storage')->read('key.key.' . $key_id);
      $record['key_provider'] = 'config';
      $record['key_provider_settings'] = ['key_value' => $value];
      $record['key_input'] = 'text_field';
      $this->container->get('config.storage')->write('key.key.' . $key_id, $record);
    }
    $this->container->get('config.factory')->reset();

    $service = $this->service([$this->tokenResponse('access-2', 'refresh-2')]);
    $this->assertSame('access-2', $service->getValidAccessToken());
    parse_str((string) $this->history[0]['request']->getBody(), $form);
    $this->assertSame('refresh-1', $form['refresh_token']);

    // A config import can also delete the Keys.
    Key::load(AuthService::ACCESS_KEY_ID)->delete();
    $this->assertSame('access-2', $this->service()->getValidAccessToken());
  }

  /**
   * A refresh writes no config.
   */
  public function testRefreshWritesNoConfig(): void {
    $this->service()->persistTokens([
      'access_token' => 'access-1',
      'refresh_token' => 'refresh-1',
      'expires_in' => 30,
    ]);
    $names = [
      'ai_provider_quant_cloud.settings',
      'key.key.' . AuthService::ACCESS_KEY_ID,
      'key.key.' . AuthService::REFRESH_KEY_ID,
    ];
    $before = array_map([$this, 'rawConfig'], $names);

    $this->assertSame('access-2', $this->service([$this->tokenResponse('access-2', 'refresh-2')])->getValidAccessToken());

    $this->assertSame($before, array_map([$this, 'rawConfig'], $names));
    $this->assertSame('refresh-2', $this->container->get('ai_provider_quant_cloud.token_store')->get(OAuthTokenStore::REFRESH_TOKEN));
  }

  /**
   * Create a Key that stores its value in config, as earlier releases did.
   */
  protected function createConfigKey(string $key_id, string $value): void {
    Key::create([
      'id' => $key_id,
      'label' => $key_id,
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_input' => 'text_field',
      'key_provider_settings' => ['key_value' => $value],
    ])->save();
  }

  /**
   * Set the module's auth settings.
   */
  protected function setAuthSettings(string $method, string $access_key_id): void {
    $this->config('ai_provider_quant_cloud.settings')
      ->set('auth.method', $method)
      ->set('auth.access_token_key', $access_key_id)
      ->save();
  }

  /**
   * The update moves a 1.0.0 legacy token and drops its unrefreshable expiry.
   */
  public function testUpdateMovesLegacyToken(): void {
    $this->createConfigKey(AuthService::ACCESS_KEY_ID, 'legacy-access');
    $this->setAuthSettings('oauth', AuthService::ACCESS_KEY_ID);
    // Release 1.0.0 stored a default one-hour expiry.
    $this->container->get('state')->set(AuthService::LEGACY_EXPIRES_AT_STATE, time() - 100);

    $message = ai_provider_quant_cloud_update_10004();

    $this->assertStringContainsString(AuthService::ACCESS_KEY_ID, $message);
    $this->assertStringNotContainsString('legacy-access', $this->rawConfig('key.key.' . AuthService::ACCESS_KEY_ID));
    $this->assertNull($this->container->get('state')->get(AuthService::LEGACY_EXPIRES_AT_STATE));
    $this->assertSame('legacy-access', $this->service()->getValidAccessToken());
    $this->assertSame(AuthService::RECONNECT_LEGACY_TOKEN, $this->service()->getReconnectReason());

    // A second run changes nothing.
    $this->assertStringContainsString('No OAuth token values', ai_provider_quant_cloud_update_10004());
    $this->assertSame('legacy-access', $this->service()->getValidAccessToken());
  }

  /**
   * The update moves a 1.x token pair in place and does not stamp the site.
   */
  public function testUpdateMovesRefreshTokenInPlaceWithoutFingerprint(): void {
    $this->createConfigKey(AuthService::ACCESS_KEY_ID, 'access-x');
    $this->createConfigKey(AuthService::REFRESH_KEY_ID, 'refresh-x');
    $uuid = Key::load(AuthService::REFRESH_KEY_ID)->uuid();
    $this->setAuthSettings('oauth', AuthService::ACCESS_KEY_ID);
    $expires_at = time() + 86400;
    $this->container->get('state')->set(AuthService::LEGACY_EXPIRES_AT_STATE, $expires_at);
    $this->container->get('state')->set(AuthService::LEGACY_FINGERPRINT_STATE, 'old-formula');

    ai_provider_quant_cloud_update_10004();

    $store = $this->container->get('ai_provider_quant_cloud.token_store');
    $this->assertSame('refresh-x', $store->get(OAuthTokenStore::REFRESH_TOKEN));
    $this->assertSame($expires_at, $store->get(OAuthTokenStore::EXPIRES_AT));
    $this->assertNull($store->get(OAuthTokenStore::FINGERPRINT));
    $this->assertNull($this->container->get('state')->get(AuthService::LEGACY_FINGERPRINT_STATE));
    $key = Key::load(AuthService::REFRESH_KEY_ID);
    $this->assertSame($uuid, $key->uuid());
    $this->assertSame(AuthService::KEY_PROVIDER_ID, $key->getKeyProvider()->getPluginId());
    $this->assertSame('refresh-x', $key->getKeyValue(TRUE));
    $this->assertNull($this->service()->getReconnectReason());
    $this->assertSame([], $this->service()->getUnconvertedKeyIds());
  }

  /**
   * Between the code deploy and the update, the old Key's token works.
   */
  public function testTokenFromOldKeyBeforeUpdate(): void {
    $this->createConfigKey(AuthService::ACCESS_KEY_ID, 'pre-update-access');
    $this->createConfigKey(AuthService::REFRESH_KEY_ID, 'pre-update-refresh');
    $this->setAuthSettings('oauth', AuthService::ACCESS_KEY_ID);

    $service = $this->service([$this->tokenResponse('access-2', 'refresh-2')]);
    $this->assertSame('pre-update-access', $service->getValidAccessToken());
    $service->refreshFromCron();
    $this->assertSame([], $this->history);

    $requirements = $this->container->get('module_handler')->invokeAll('runtime_requirements');
    $this->assertArrayHasKey('ai_provider_quant_cloud_oauth_keys', $requirements);
  }

  /**
   * Saving an old Key export keeps the pointer and keeps the token out.
   */
  public function testSavingOldKeyExportKeepsPointer(): void {
    $this->service()->persistTokens(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3600]);

    foreach ([FALSE, TRUE] as $syncing) {
      $key = Key::load(AuthService::ACCESS_KEY_ID);
      $key->setSyncing($syncing);
      $key->set('key_provider', 'config');
      $key->set('key_provider_settings', ['key_value' => 'stale-access']);
      $key->set('key_input', 'text_field');
      $key->save();

      $raw = $this->rawConfig('key.key.' . AuthService::ACCESS_KEY_ID);
      $this->assertStringNotContainsString('stale-access', $raw);
      $this->assertStringContainsString(AuthService::KEY_PROVIDER_ID, $raw);
    }
    $this->assertSame('access-1', $this->service()->getValidAccessToken());
  }

  /**
   * Deleting an old Key before the update keeps its token.
   */
  public function testDeletingOldKeyBeforeUpdateKeepsToken(): void {
    $this->createConfigKey(AuthService::ACCESS_KEY_ID, 'pre-update-access');
    $this->setAuthSettings('oauth', AuthService::ACCESS_KEY_ID);

    Key::load(AuthService::ACCESS_KEY_ID)->delete();

    $this->assertSame('pre-update-access', $this->container->get('ai_provider_quant_cloud.token_store')->get(OAuthTokenStore::ACCESS_TOKEN));
    $this->assertSame('pre-update-access', $this->service()->getValidAccessToken());
  }

  /**
   * The keep-connection form needs the restricted permission and stamps.
   */
  public function testKeepConnectionFormNeedsRestrictedPermission(): void {
    $this->container->get('router.builder')->rebuild();
    $permissions = $this->container->get('user.permissions')->getPermissions();
    $this->assertTrue($permissions['confirm quant cloud same site']['restrict access']);

    $admin = $this->createMock(AccountInterface::class);
    $admin->method('hasPermission')->willReturnCallback(fn($p) => $p === 'administer site configuration');
    $confirmer = $this->createMock(AccountInterface::class);
    $confirmer->method('hasPermission')->willReturnCallback(fn($p) => $p === 'confirm quant cloud same site');
    $access = $this->container->get('access_manager');
    $this->assertFalse($access->checkNamedRoute('ai_provider_quant_cloud.keep_connection', [], $admin));
    $this->assertTrue($access->checkNamedRoute('ai_provider_quant_cloud.keep_connection', [], $confirmer));

    $this->service()->persistTokens(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3600]);
    $this->setSetting('hash_salt', 'a-different-environment');
    $this->assertSame(AuthService::RECONNECT_OTHER_SITE, $this->service()->getReconnectReason());

    $form_state = new FormState();
    $form_state->setValues(['confirm' => 1]);
    $this->container->get('form_builder')->submitForm(KeepConnectionForm::class, $form_state);

    $this->assertSame([], $form_state->getErrors());
    $this->assertNull($this->service()->getReconnectReason());
  }

  /**
   * The update leaves a manual-mode Key alone.
   */
  public function testUpdateLeavesManualKey(): void {
    $this->createConfigKey('my_manual_key', 'manual-token');
    $this->setAuthSettings('manual', 'my_manual_key');

    $this->assertStringContainsString('No OAuth token values', ai_provider_quant_cloud_update_10004());

    $this->assertStringContainsString('manual-token', $this->rawConfig('key.key.my_manual_key'));
    $this->assertSame('manual-token', $this->service()->getValidAccessToken());
  }

  /**
   * An OAuth token without a refresh token shows a status report warning.
   */
  public function testLegacyTokenShowsRequirementOnce(): void {
    $this->service()->persistTokens(['access_token' => 'legacy-access']);

    $requirements = $this->container->get('module_handler')->invokeAll('runtime_requirements');
    $this->assertArrayHasKey('ai_provider_quant_cloud_oauth', $requirements);
    $this->assertStringContainsString('Reconnect Quant Cloud to switch to refreshable tokens', (string) $requirements['ai_provider_quant_cloud_oauth']['description']);
    // The procedural hook is marked legacy, so it does not add a duplicate.
    $legacy = $this->container->get('module_handler')->invokeAll('requirements', ['runtime']);
    $this->assertArrayNotHasKey('ai_provider_quant_cloud_oauth', $legacy);
    $this->assertSame('legacy-access', $this->service()->getValidAccessToken());
  }

  /**
   * A database copy keeps working, does not refresh, and can be confirmed.
   */
  public function testCopiedDatabaseNeedsConfirmation(): void {
    $this->service()->persistTokens([
      'access_token' => 'access-1',
      'refresh_token' => 'refresh-1',
      'expires_in' => 30,
    ]);
    $this->assertSame([], ai_provider_quant_cloud_requirements('runtime'));

    $this->setSetting('hash_salt', 'a-different-environment');
    $service = $this->service([$this->tokenResponse('access-2', 'refresh-2')]);

    $this->assertSame('access-1', $service->getValidAccessToken());
    $this->assertSame([], $this->history);
    $requirements = ai_provider_quant_cloud_requirements('runtime');
    $this->assertStringContainsString('issued to another site', (string) $requirements['ai_provider_quant_cloud_oauth']['description']);

    $service->confirmSameSite();
    $this->assertSame('access-2', $service->getValidAccessToken());
  }

}
