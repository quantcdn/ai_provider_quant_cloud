<?php

namespace Drupal\Tests\ai_provider_quant_cloud\Unit;

use Drupal\ai_provider_quant_cloud\PortalUrl;
use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;

/**
 * One portal URL rule for OAuth and the API clients.
 *
 * @coversDefaultClass \Drupal\ai_provider_quant_cloud\PortalUrl
 * @group ai_provider_quant_cloud
 */
class PortalUrlTest extends UnitTestCase {

  /**
   * Every platform maps to its portal; an unknown one to QuantCDN.
   */
  public function testPlatforms(): void {
    new Settings([]);
    $this->assertSame('https://dash.quantgov.cloud', PortalUrl::forPlatform('quantgov'));
    $this->assertSame('https://dash.stage.quantgov.cloud', PortalUrl::forPlatform('quantgov_staging'));
    $this->assertSame('https://portal.stage.quantcdn.io', PortalUrl::forPlatform('quantcdn_staging'));
    $this->assertSame('https://dashboard.quantcdn.io', PortalUrl::forPlatform(NULL));
    $this->assertSame('https://dashboard.quantcdn.io', PortalUrl::forPlatform('other'));
  }

  /**
   * The config value wins over the map; the settings.php override wins.
   */
  public function testOverrides(): void {
    new Settings([]);
    $this->assertSame('https://dash.example', PortalUrl::forPlatform('quantgov', 'https://dash.example/'));

    new Settings(['ai_provider_quant_cloud_dashboard_url' => 'http://localhost:8002/']);
    $this->assertSame('http://localhost:8002', PortalUrl::forPlatform('quantgov', 'https://dash.example'));
  }

}
