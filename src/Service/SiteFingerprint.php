<?php

declare(strict_types=1);

namespace Drupal\ai_provider_quant_cloud\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;

/**
 * A per-component fingerprint of this site that a database copy lacks.
 *
 * The site UUID is in the database, so a copy keeps it. The other
 * components come from settings.php, the database connection settings and
 * the hosting environment, which a copy to another environment does not
 * take with it. The database host and port are not used, because hosting
 * platforms change them when they move a database. The base URL is not
 * used: drush and cron often have none, and one site can have many host
 * names.
 *
 * Each component is stored as a hash, so the stored fingerprint holds no
 * value, and a mismatch can name the component that differs.
 */
class SiteFingerprint {

  /**
   * Hosting environment variables that name the environment.
   *
   * These hosts set them in the container environment, so web requests,
   * drush and cron see the same value. LAGOON_GIT_BRANCH is not used:
   * LAGOON_ENVIRONMENT already names the environment, and a branch can be
   * renamed under one environment.
   */
  public const ENVIRONMENT_VARIABLES = [
    'PLATFORM_ENVIRONMENT',
    'LAGOON_ENVIRONMENT',
    'PANTHEON_ENVIRONMENT',
    'AH_SITE_ENVIRONMENT',
  ];

  /**
   * Readable names of the components, for messages.
   */
  protected const LABELS = [
    'override' => '$settings[\'ai_provider_quant_cloud_site_id\']',
    'hash_salt' => '$settings[\'hash_salt\']',
    'database' => 'database name',
    'prefix' => 'database table prefix',
    'uuid' => 'site UUID',
    'legacy' => 'fingerprint from a development build',
  ];

  /**
   * Constructs a SiteFingerprint.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The fingerprint of this site: component name => hash.
   *
   * @return array<string,string>
   *   One hash per component.
   */
  public function compute(): array {
    $site_id = (string) Settings::get('ai_provider_quant_cloud_site_id', '');
    $values = $site_id !== '' ? ['override' => $site_id] : $this->getAutomaticValues();
    $values['uuid'] = (string) $this->configFactory->get('system.site')->get('uuid');

    $hashes = [];
    foreach ($values as $name => $value) {
      $hashes[$name] = hash('sha256', $name . "\0" . $value);
    }
    return $hashes;
  }

  /**
   * The components that differ between a stored fingerprint and this site.
   *
   * @param array<string,string> $stored
   *   A fingerprint from compute().
   *
   * @return string[]
   *   Readable component names; empty when the fingerprints match.
   */
  public function getDifferences(array $stored): array {
    if (isset($stored['legacy'])) {
      // A development-build fingerprint that did not match this site.
      return [$this->label('legacy')];
    }
    $current = $this->compute();
    $names = array_keys($stored + $current);
    $different = array_filter($names, static function (string $name) use ($stored, $current): bool {
      // A hosting variable counts only when both sides have it: a value set
      // for one context only must not split one site in two.
      if (str_starts_with($name, 'env:') && (!isset($stored[$name]) || !isset($current[$name]))) {
        return FALSE;
      }
      return !hash_equals((string) ($stored[$name] ?? ''), (string) ($current[$name] ?? ''));
    });
    return array_values(array_map([$this, 'label'], $different));
  }

  /**
   * The single-string fingerprint of development builds 154c5cf to 1fb7f73.
   *
   * Used only to recognise a token record that this site stored then.
   */
  public function computeLegacyStoreFormat(): string {
    return $this->legacyHash(FALSE);
  }

  /**
   * The state fingerprint of development build 1a0ad4f.
   *
   * That formula also used the database host and port.
   */
  public function computeLegacyStateFormat(): string {
    return $this->legacyHash(TRUE);
  }

  /**
   * The hash of a development-build formula.
   */
  protected function legacyHash(bool $with_host): string {
    $site_id = (string) Settings::get('ai_provider_quant_cloud_site_id', '');
    if ($site_id !== '') {
      $parts = ['site_id', $site_id];
    }
    else {
      $db = Database::getConnectionInfo()['default'] ?? [];
      $prefix = $db['prefix'] ?? '';
      $parts = ['auto', (string) Settings::get('hash_salt', '')];
      if ($with_host) {
        $parts[] = (string) ($db['host'] ?? '');
        $parts[] = (string) ($db['port'] ?? '');
      }
      $parts[] = (string) ($db['database'] ?? '');
      $parts[] = is_array($prefix) ? (string) ($prefix['default'] ?? '') : (string) $prefix;
    }
    $parts[] = (string) $this->configFactory->get('system.site')->get('uuid');
    return hash('sha256', implode("\0", $parts));
  }

  /**
   * The readable name of a component.
   */
  protected function label(string $name): string {
    if (str_starts_with($name, 'env:')) {
      return 'environment variable ' . substr($name, 4);
    }
    return self::LABELS[$name] ?? $name;
  }

  /**
   * The automatic component values.
   *
   * @return array<string,string>
   *   hash_salt, database name, table prefix and any hosting environment
   *   variables that are set.
   */
  protected function getAutomaticValues(): array {
    $db = Database::getConnectionInfo()['default'] ?? [];
    $prefix = $db['prefix'] ?? '';
    $values = [
      'hash_salt' => (string) Settings::get('hash_salt', ''),
      'database' => (string) ($db['database'] ?? ''),
      'prefix' => is_array($prefix) ? (string) ($prefix['default'] ?? '') : (string) $prefix,
    ];
    foreach (self::ENVIRONMENT_VARIABLES as $variable) {
      $value = $this->readEnvironment($variable);
      if ($value !== '') {
        $values['env:' . $variable] = $value;
      }
    }
    return $values;
  }

  /**
   * Read an environment variable from getenv() or $_ENV.
   *
   * $_SERVER is not read: on some hosts it carries web-only values that
   * drush and cron do not see.
   */
  protected function readEnvironment(string $variable): string {
    $value = getenv($variable);
    if (is_string($value) && $value !== '') {
      return $value;
    }
    return (string) ($_ENV[$variable] ?? '');
  }

}
