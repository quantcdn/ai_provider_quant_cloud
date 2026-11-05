<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_quant_cloud\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai_provider_quant_cloud\Service\AuthService;
use Drupal\key\Entity\Key;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that AuthService stores tokens in real Key entities correctly.
 */
#[Group('ai_provider_quant_cloud')]
#[RunTestsInSeparateProcesses]
class AuthServiceKeyStorageTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'key', 'ai', 'ai_provider_quant_cloud'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->container->get('module_handler')->loadInclude('ai_provider_quant_cloud', 'install');
  }

  /**
   * Build the service from the container services.
   */
  protected function service(): AuthService {
    return new AuthService(
      $this->container->get('http_client'),
      $this->container->get('config.factory'),
      $this->container->get('logger.factory'),
      $this->container->get('key.repository'),
      $this->container->get('entity_type.manager'),
      $this->container->get('state'),
      $this->container->get('lock'),
      $this->container->get('cache.config'),
    );
  }

  /**
   * A second persist updates the same Key entities in place.
   */
  public function testPersistTokensUpdatesKeysInPlace(): void {
    $service = $this->service();
    $service->persistTokens([
      'access_token' => 'access-1',
      'refresh_token' => 'refresh-1',
      'expires_in' => 3600,
    ]);
    $first_uuid = Key::load(AuthService::ACCESS_KEY_ID)->uuid();

    $service->persistTokens([
      'access_token' => 'access-2',
      'refresh_token' => 'refresh-2',
      'expires_in' => 3600,
    ]);

    $this->container->get('entity_type.manager')->getStorage('key')->resetCache();
    $access = Key::load(AuthService::ACCESS_KEY_ID);
    $this->assertSame($first_uuid, $access->uuid());
    $this->assertSame('access-2', $access->getKeyValue(TRUE));
    $this->assertSame('refresh-2', Key::load(AuthService::REFRESH_KEY_ID)->getKeyValue(TRUE));
    $this->assertSame('access-2', $service->getAccessToken(TRUE));
    $this->assertSame('access-2', $service->getValidAccessToken());
  }

  /**
   * A database copy with another settings.php does not use the tokens.
   */
  public function testCopiedTokensNeedReconnect(): void {
    $this->service()->persistTokens([
      'access_token' => 'access-1',
      'refresh_token' => 'refresh-1',
      'expires_in' => 3600,
    ]);
    $this->assertSame([], ai_provider_quant_cloud_requirements('runtime'));

    $this->setSetting('hash_salt', 'a-different-environment');
    $service = $this->service();

    $this->assertNull($service->getValidAccessToken());
    $requirements = ai_provider_quant_cloud_requirements('runtime');
    $this->assertArrayHasKey('ai_provider_quant_cloud_oauth', $requirements);
    $this->assertStringContainsString('belong to another site', (string) $requirements['ai_provider_quant_cloud_oauth']['description']);
  }

  /**
   * An OAuth token without a refresh token shows a status report warning.
   */
  public function testLegacyTokenShowsRequirementWarning(): void {
    $this->service()->persistTokens(['access_token' => 'legacy-access']);

    $requirements = ai_provider_quant_cloud_requirements('runtime');

    $this->assertArrayHasKey('ai_provider_quant_cloud_oauth', $requirements);
    $this->assertStringContainsString('Reconnect Quant Cloud to switch to refreshable tokens', (string) $requirements['ai_provider_quant_cloud_oauth']['description']);
    $this->assertSame('legacy-access', $this->service()->getValidAccessToken());
  }

}
