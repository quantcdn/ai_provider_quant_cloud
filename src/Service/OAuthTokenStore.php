<?php

declare(strict_types=1);

namespace Drupal\ai_provider_quant_cloud\Service;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;

/**
 * Stores the OAuth token values and their runtime data outside config.
 *
 * The values live in the key_value collection 'ai_provider_quant_cloud.oauth'.
 * Config export never contains them, config import cannot change them, and
 * config_readonly does not block a token rotation. Each read goes to the
 * database, so a peer process's rotation is visible at once.
 */
class OAuthTokenStore {

  /**
   * The key_value collection name.
   */
  public const COLLECTION = 'ai_provider_quant_cloud.oauth';

  /**
   * The access token.
   */
  public const ACCESS_TOKEN = 'access_token';

  /**
   * The refresh token.
   */
  public const REFRESH_TOKEN = 'refresh_token';

  /**
   * Absolute access-token expiry (Unix time).
   */
  public const EXPIRES_AT = 'expires_at';

  /**
   * Access-token lifetime in seconds, as issued.
   */
  public const LIFETIME = 'lifetime';

  /**
   * Fingerprint of the site that owns the tokens.
   */
  public const FINGERPRINT = 'fingerprint';

  /**
   * The final refusal from the portal: ['error' => ..., 'description' => ...].
   */
  public const REFUSAL = 'refusal';

  /**
   * Unix time before which web requests do not try a refresh again.
   */
  public const RETRY_AFTER = 'retry_after';

  /**
   * Unix time before which cron does not try a refresh again.
   */
  public const CRON_RETRY_AFTER = 'cron_retry_after';

  /**
   * A refresh whose outcome is not known yet.
   *
   * ['since' => Unix time, 'token' => SHA-256 of the refresh token,
   * 'attempts' => number of sends of that token].
   *
   * It is written before the refresh request and removed on a definite
   * result. A process that finds a recent one sends the same refresh token
   * again, so that the portal re-issues a lost rotation.
   */
  public const PENDING = 'pending';

  /**
   * Identifiers of conditions that are already logged.
   */
  public const LOGGED = 'logged';

  /**
   * Unix time of the last cron keep-alive call for a legacy token.
   *
   * A legacy token (from release 1.0.0) has no refresh token.
   */
  public const KEEPALIVE_AT = 'keepalive_at';

  /**
   * All names in the collection.
   */
  protected const NAMES = [
    self::ACCESS_TOKEN,
    self::REFRESH_TOKEN,
    self::EXPIRES_AT,
    self::LIFETIME,
    self::FINGERPRINT,
    self::REFUSAL,
    self::RETRY_AFTER,
    self::CRON_RETRY_AFTER,
    self::PENDING,
    self::LOGGED,
    self::KEEPALIVE_AT,
  ];

  /**
   * The key_value store.
   */
  protected KeyValueStoreInterface $store;

  /**
   * Constructs an OAuthTokenStore.
   */
  public function __construct(KeyValueFactoryInterface $key_value_factory) {
    $this->store = $key_value_factory->get(self::COLLECTION);
  }

  /**
   * Read every stored value in one query.
   *
   * @return array<string,mixed>
   *   The stored values, keyed by the constants of this class.
   */
  public function getAll(): array {
    return $this->store->getMultiple(self::NAMES);
  }

  /**
   * Read one value.
   */
  public function get(string $name): mixed {
    return $this->store->get($name);
  }

  /**
   * Write one value.
   */
  public function set(string $name, mixed $value): void {
    $this->store->set($name, $value);
  }

  /**
   * Write several values.
   *
   * @param array<string,mixed> $values
   *   Values keyed by the constants of this class.
   */
  public function setMany(array $values): void {
    $this->store->setMultiple($values);
  }

  /**
   * Store a token response.
   *
   * The refresh token is written first. The portal has already invalidated
   * the previous one, so a partial write must not lose the new one. The
   * expiry is written after the access token.
   *
   * @param array $token_data
   *   The token response: access_token, and optionally refresh_token and
   *   expires_in.
   */
  public function saveTokens(array $token_data): void {
    $values = [];
    if (!empty($token_data['refresh_token'])) {
      $values[self::REFRESH_TOKEN] = (string) $token_data['refresh_token'];
    }
    $values[self::ACCESS_TOKEN] = (string) $token_data['access_token'];
    $this->store->setMultiple($values);

    $expires_in = isset($token_data['expires_in']) ? (int) $token_data['expires_in'] : 0;
    if ($expires_in > 0) {
      $this->store->setMultiple([
        self::EXPIRES_AT => time() + $expires_in,
        self::LIFETIME => $expires_in,
      ]);
      return;
    }
    $this->store->deleteMultiple([self::EXPIRES_AT, self::LIFETIME]);
  }

  /**
   * Remove every stored value.
   */
  public function clear(): void {
    $this->store->deleteMultiple(self::NAMES);
  }

  /**
   * Remove the given values.
   *
   * @param string[] $names
   *   Names from the constants of this class.
   */
  public function delete(array $names): void {
    $this->store->deleteMultiple($names);
  }

}
