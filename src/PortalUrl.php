<?php

namespace Drupal\ai_provider_quant_cloud;

use Drupal\Core\Site\Settings;

/**
 * The Quant dashboard (portal) base URL. One rule for OAuth and the API.
 *
 * A site can point every platform at another portal with
 * $settings['ai_provider_quant_cloud_dashboard_url'] in settings.php, for a
 * staging or local portal. Production sites leave it unset.
 */
final class PortalUrl {

  public const URLS = [
    'quantcdn' => 'https://dashboard.quantcdn.io',
    'quantgov' => 'https://dash.quantgov.cloud',
    'quantcdn_staging' => 'https://portal.stage.quantcdn.io',
    'quantgov_staging' => 'https://dash.stage.quantgov.cloud',
  ];

  /**
   * The base URL, without a trailing slash.
   *
   * @param string|null $platform
   *   The configured platform.
   * @param string|null $configured
   *   The platforms.<platform>.dashboard_url config value, if any.
   */
  public static function forPlatform(?string $platform, ?string $configured = NULL): string {
    $url = Settings::get('ai_provider_quant_cloud_dashboard_url')
      ?: ($configured ?: (self::URLS[$platform ?: 'quantcdn'] ?? self::URLS['quantcdn']));

    return rtrim($url, '/');
  }

}
