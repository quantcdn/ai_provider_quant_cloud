<?php

declare(strict_types=1);

namespace Drupal\ai_provider_quant_cloud\Hook;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Attribute\LegacyRequirementsHook;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\ai_provider_quant_cloud\Service\AuthService;

/**
 * Status report entry for the Quant Cloud OAuth connection.
 */
class RuntimeRequirementsHook {

  /**
   * Constructs a RuntimeRequirementsHook.
   */
  public function __construct(
    protected AuthService $authService,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   *
   * Before drupal:11.3, the procedural hook_requirements() reports this
   * entry. Returning nothing there avoids a duplicate on drupal:11.2, which
   * invokes both hooks.
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    if (!class_exists(LegacyRequirementsHook::class)) {
      return [];
    }
    return self::build($this->authService);
  }

  /**
   * Build the status report entry.
   *
   * @return array
   *   The requirements array, empty when no action is needed.
   */
  public static function build(AuthService $auth_service): array {
    return self::buildConnectionEntry($auth_service) + self::buildKeyEntry($auth_service);
  }

  /**
   * The entry that asks the administrator to reconnect or confirm.
   */
  protected static function buildConnectionEntry(AuthService $auth_service): array {
    $message = $auth_service->getReconnectMessage();
    if ($message === NULL) {
      return [];
    }

    $is_error = $auth_service->getReconnectReason() !== AuthService::RECONNECT_LEGACY_TOKEN;
    return [
      'ai_provider_quant_cloud_oauth' => [
        'title' => new TranslatableMarkup('Quant Cloud AI Provider'),
        'value' => new TranslatableMarkup('Action required'),
        'description' => new TranslatableMarkup('@message <a href=":url">Open the Quant Cloud settings</a>.', [
          '@message' => $message,
          ':url' => Url::fromRoute('ai_provider_quant_cloud.settings_form')->toString(),
        ]),
        'severity' => self::severity($is_error),
      ],
    ];
  }

  /**
   * The entry for module Keys that still hold a token in config.
   */
  protected static function buildKeyEntry(AuthService $auth_service): array {
    $key_ids = $auth_service->getUnconvertedKeyIds();
    if ($key_ids === []) {
      return [];
    }
    return [
      'ai_provider_quant_cloud_oauth_keys' => [
        'title' => new TranslatableMarkup('Quant Cloud AI Provider keys'),
        'value' => new TranslatableMarkup('Token in configuration'),
        'description' => new TranslatableMarkup('The Keys @keys still hold a Quant Cloud token in configuration. Run the database updates (drush updb), then export the configuration again (drush cex), so that the token leaves your config sync directory.', [
          '@keys' => implode(', ', $key_ids),
        ]),
        'severity' => self::severity(FALSE),
      ],
    ];
  }

  /**
   * The severity value that this Drupal version expects.
   */
  protected static function severity(bool $is_error): mixed {
    // RequirementSeverity exists from drupal:11.2; drupal:12 requires it.
    if (class_exists(RequirementSeverity::class)) {
      return $is_error ? RequirementSeverity::Error : RequirementSeverity::Warning;
    }
    return $is_error ? 2 : 1;
  }

}
