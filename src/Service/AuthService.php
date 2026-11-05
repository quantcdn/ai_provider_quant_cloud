<?php

namespace Drupal\ai_provider_quant_cloud\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LogLevel;

/**
 * Service for managing authentication with Quant Cloud API.
 *
 * OAuth token values live in the module's key_value store (OAuthTokenStore),
 * not in config. The Key entities that settings refer to only point at that
 * store, so config export and import never carry or change a token.
 */
class AuthService {

  /**
   * Key entity ID that points at the OAuth access token.
   */
  public const ACCESS_KEY_ID = 'quant_cloud_oauth_access_token';

  /**
   * Key entity ID that points at the OAuth refresh token.
   */
  public const REFRESH_KEY_ID = 'quant_cloud_oauth_refresh_token';

  /**
   * The Key provider plugin that reads from the token store.
   */
  public const KEY_PROVIDER_ID = 'quant_cloud_oauth';

  /**
   * The module's Key IDs and the token that each one points at.
   */
  public const MODULE_KEYS = [
    self::ACCESS_KEY_ID => 'access',
    self::REFRESH_KEY_ID => 'refresh',
  ];

  /**
   * The token store name for each module Key ID.
   */
  protected const MODULE_KEY_STORE_NAMES = [
    self::ACCESS_KEY_ID => OAuthTokenStore::ACCESS_TOKEN,
    self::REFRESH_KEY_ID => OAuthTokenStore::REFRESH_TOKEN,
  ];

  /**
   * State key that held the access-token expiry before 1.1.0.
   */
  public const LEGACY_EXPIRES_AT_STATE = 'quant_cloud_oauth_expires_at';

  /**
   * State key that held a site fingerprint during 1.1.0 development.
   *
   * It used another formula, so it is deleted and not copied.
   */
  public const LEGACY_FINGERPRINT_STATE = 'quant_cloud_oauth_site_fingerprint';

  /**
   * Reconnect reason: the stored tokens were issued to another site.
   */
  public const RECONNECT_OTHER_SITE = 'other_site';

  /**
   * Reconnect reason: OAuth is connected but no refresh token is stored.
   */
  public const RECONNECT_LEGACY_TOKEN = 'legacy_token';

  /**
   * Reconnect reason: the portal refused the refresh token for good.
   */
  public const RECONNECT_REFUSED = 'refused';

  /**
   * Lock name that serializes refresh-token use across processes.
   */
  protected const REFRESH_LOCK_ID = 'ai_provider_quant_cloud.oauth_refresh';

  /**
   * Lock lifetime.
   *
   * It must cover a refresh, the recovery pause and one retry (the retry
   * runs in CLI only): 60 + 15 + 60 = 135 seconds.
   */
  protected const REFRESH_LOCK_TIMEOUT = 150;

  /**
   * Seconds to wait before the one retry after an unknown outcome.
   *
   * The portal re-issues a lost rotation once, for the previous refresh
   * token, within five minutes.
   */
  protected const RECOVERY_DELAY = 15;

  /**
   * Seconds in which web requests do not refresh after an unknown outcome.
   *
   * Cron still tries. This keeps every request from waiting on a slow
   * portal.
   */
  protected const REQUEST_BACKOFF = 60;

  /**
   * Seconds in which cron does not refresh after an unknown outcome.
   *
   * A recent pending refresh overrides this: it is retried at once.
   */
  protected const CRON_BACKOFF = 600;

  /**
   * Seconds in which a pending refresh is retried with the same token.
   *
   * The portal re-issues a lost rotation within five minutes.
   */
  protected const PENDING_WINDOW = 240;

  /**
   * Maximum seconds to wait for a peer that holds the refresh lock.
   */
  protected const REFRESH_LOCK_WAIT = 30;

  /**
   * HTTP timeout for the refresh grant.
   *
   * The portal commits a rotation even when the client disconnects, and it
   * can stall for about 100 s once an hour. A long timeout lowers the chance
   * that a rotated pair is lost because this side stopped waiting.
   */
  protected const REFRESH_HTTP_TIMEOUT = 60;

  /**
   * Seconds before expiry at which a request refreshes the access token.
   *
   * This is the fallback. Cron refreshes much earlier (CRON_MARGIN).
   */
  protected const EXPIRY_MARGIN = 60;

  /**
   * Seconds before expiry at which cron refreshes the access token.
   *
   * 45 days. A refresh also keeps the chain inside the portal's 60-day idle
   * limit.
   */
  protected const CRON_MARGIN = 3888000;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The key repository.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The state service (read only to move pre-1.1.0 values).
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The lock backend (used to serialize concurrent refresh attempts).
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected $lock;

  /**
   * The OAuth token store.
   *
   * @var \Drupal\ai_provider_quant_cloud\Service\OAuthTokenStore
   */
  protected $tokenStore;

  /**
   * The default database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The site fingerprint.
   *
   * @var \Drupal\ai_provider_quant_cloud\Service\SiteFingerprint
   */
  protected $siteFingerprint;

  /**
   * Constructs an AuthService.
   */
  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    KeyRepositoryInterface $key_repository,
    EntityTypeManagerInterface $entity_type_manager,
    StateInterface $state,
    LockBackendInterface $lock,
    OAuthTokenStore $token_store,
    Connection $database,
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('ai_provider_quant_cloud');
    $this->keyRepository = $key_repository;
    $this->entityTypeManager = $entity_type_manager;
    $this->state = $state;
    $this->lock = $lock;
    $this->tokenStore = $token_store;
    $this->database = $database;
    $this->siteFingerprint = new SiteFingerprint($config_factory);
  }

  /**
   * Get the stored access token, without a refresh.
   *
   * API calls must use getValidAccessToken() instead.
   *
   * @param bool $fresh
   *   TRUE to bypass the per-request Key value cache. The OAuth token store
   *   is always read from the database.
   *
   * @return string|null
   *   The access token or NULL if not configured.
   */
  public function getAccessToken(bool $fresh = FALSE): ?string {
    if ($this->usesModuleTokens()) {
      return $this->storedToken(OAuthTokenStore::ACCESS_TOKEN) ?? $this->readUnmigratedKeyToken();
    }
    return $this->readTokenKey('auth.access_token_key', $fresh);
  }

  /**
   * The access token from a module Key that update 10004 has not moved yet.
   *
   * Between a code deploy and "drush updb", the token is still in the Key's
   * config. It is read, but never refreshed from here.
   */
  protected function readUnmigratedKeyToken(): ?string {
    $key = $this->keyRepository->getKey(self::ACCESS_KEY_ID);
    if (!$key instanceof KeyInterface || $this->isPointerKey($key)) {
      return NULL;
    }
    return $key->getKeyValue(TRUE) ?: NULL;
  }

  /**
   * Whether a Key uses the pointer provider, not a stored value.
   */
  protected function isPointerKey(KeyInterface $key): bool {
    return $key->getKeyProvider()->getPluginId() === self::KEY_PROVIDER_ID;
  }

  /**
   * Whether the site uses the OAuth tokens that this module stores.
   *
   * Manual mode, and any Key that an administrator selected, use the Key
   * module as before.
   */
  public function usesModuleTokens(): bool {
    $config = $this->configFactory->get('ai_provider_quant_cloud.settings');
    return $config->get('auth.method') === 'oauth'
      && $config->get('auth.access_token_key') === self::ACCESS_KEY_ID;
  }

  /**
   * A non-empty token string from the store, or NULL.
   */
  protected function storedToken(string $name): ?string {
    $value = $this->tokenStore->get($name);
    return is_string($value) && $value !== '' ? $value : NULL;
  }

  /**
   * Read a token from the Key entity that a settings value names.
   *
   * @param string $config_name
   *   The settings key that holds the Key entity ID.
   * @param bool $fresh
   *   TRUE to bypass the per-request Key value cache.
   */
  protected function readTokenKey(string $config_name, bool $fresh = FALSE): ?string {
    $key_id = $this->configFactory->get('ai_provider_quant_cloud.settings')->get($config_name);
    if (!$key_id) {
      return NULL;
    }
    $key = $this->keyRepository->getKey($key_id);
    if (!$key) {
      return NULL;
    }
    return $key->getKeyValue($fresh) ?: NULL;
  }

  /**
   * Get organizations available to the authenticated user via this OAuth token.
   *
   * Uses the dashboard's /api/oauth/user endpoint (works with any valid OAuth
   * token, including scoped tokens with only ai:use), and returns only the
   * organizations the user explicitly granted during the OAuth approval step.
   * The legacy /api/v2/organizations endpoint requires the projects:read scope
   * and would 403 on a scoped ai:use token.
   *
   * @return array
   *   Array of organizations with 'name' and 'machine_name' keys, or empty
   *   array on failure / no orgs.
   */
  public function getOrganizations(): array {
    $token = $this->getValidAccessToken();

    if (!$token) {
      return [];
    }

    try {
      $config = $this->configFactory->get('ai_provider_quant_cloud.settings');
      $platform = $config->get('platform') ?: 'quantcdn';
      $dashboard_url = $config->get("platforms.{$platform}.dashboard_url");
      if (!$dashboard_url) {
        $dashboard_url = $platform === 'quantgov'
          ? 'https://dash.quantgov.cloud'
          : 'https://dashboard.quantcdn.io';
      }

      $response = $this->httpClient->get($dashboard_url . '/api/oauth/user', [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Accept' => 'application/json',
        ],
        'timeout' => 10,
        'http_errors' => FALSE,
      ]);

      if ($response->getStatusCode() !== 200) {
        $this->logger->warning('Failed to fetch user/orgs: status @status', [
          '@status' => $response->getStatusCode(),
        ]);
        return [];
      }

      $data = json_decode($response->getBody()->getContents(), TRUE);
      $orgs = $data['organizations'] ?? [];

      // Normalise to the legacy shape ['name' => ..., 'machine_name' => ...]
      // so callers (form dropdown, validateToken) don't care about the source.
      return array_map(static fn(array $org) => [
        'name' => $org['name'] ?? '',
        'machine_name' => $org['machine_name'] ?? '',
      ], array_values($orgs));

    }
    catch (\Exception $e) {
      $this->logger->error('Error fetching user/orgs: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Validate the access token by fetching organizations.
   *
   * @return bool
   *   TRUE if token is valid, FALSE otherwise.
   */
  public function validateToken(): bool {
    $organizations = $this->getOrganizations();
    return !empty($organizations);
  }

  /**
   * Generate a PKCE verifier (RFC 7636).
   *
   * @return string
   *   A base64url-encoded random string (no padding).
   */
  public function generatePkceVerifier(): string {
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
  }

  /**
   * Compute the S256 PKCE challenge from a verifier.
   */
  public function computePkceChallenge(string $verifier): string {
    return rtrim(strtr(base64_encode(hash('sha256', $verifier, TRUE)), '+/', '-_'), '=');
  }

  /**
   * Get OAuth authorization URL for web flow (with PKCE S256).
   *
   * @param string $state
   *   CSRF state token.
   * @param string $redirect_uri
   *   Callback URI for Drupal.
   * @param string $code_challenge
   *   PKCE S256 challenge (base64url, no padding).
   *
   * @return string
   *   The authorization URL.
   */
  public function getAuthorizationUrl(string $state, string $redirect_uri, string $code_challenge): string {
    $config = $this->configFactory->get('ai_provider_quant_cloud.settings');
    $platform = $config->get('platform') ?: 'quantcdn';
    $dashboard_url = $config->get("platforms.{$platform}.dashboard_url");
    if (!$dashboard_url) {
      $dashboard_url = $platform === 'quantgov'
        ? 'https://dash.quantgov.cloud'
        : 'https://dashboard.quantcdn.io';
    }

    $auth_endpoint = $dashboard_url . '/oauth/authorize';

    $params = [
      'client_id' => 'drupal-ai-provider',
      'response_type' => 'code',
      'redirect_uri' => $redirect_uri,
      'state' => $state,
      'scope' => 'ai:use',
      'code_challenge' => $code_challenge,
      'code_challenge_method' => 'S256',
    ];

    return $auth_endpoint . '?' . http_build_query($params);
  }

  /**
   * Exchange authorization code for access + refresh tokens (with PKCE).
   *
   * @return array|null
   *   Token response with access_token, refresh_token, expires_in, etc.,
   *   or NULL on failure.
   */
  public function exchangeCodeForToken(string $code, string $redirect_uri, string $code_verifier): ?array {
    $config = $this->configFactory->get('ai_provider_quant_cloud.settings');
    $platform = $config->get('platform') ?: 'quantcdn';
    $dashboard_url = $config->get("platforms.{$platform}.dashboard_url");
    if (!$dashboard_url) {
      $dashboard_url = $platform === 'quantgov'
        ? 'https://dash.quantgov.cloud'
        : 'https://dashboard.quantcdn.io';
    }

    $token_endpoint = $dashboard_url . '/oauth/token';

    try {
      $response = $this->httpClient->post($token_endpoint, [
        'form_params' => [
          'grant_type' => 'authorization_code',
          'code' => $code,
          'redirect_uri' => $redirect_uri,
          'client_id' => 'drupal-ai-provider',
          'code_verifier' => $code_verifier,
        ],
        'timeout' => 30,
        'http_errors' => FALSE,
      ]);

      if ($response->getStatusCode() !== 200) {
        $this->logger->error('Token exchange failed with status @status: @reason', [
          '@status' => $response->getStatusCode(),
          '@reason' => $response->getReasonPhrase(),
        ]);
        return NULL;
      }

      $data = json_decode($response->getBody()->getContents(), TRUE);
      return isset($data['access_token']) ? $data : NULL;

    }
    catch (\Exception $e) {
      $this->logger->error('Failed to exchange OAuth code: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Store the tokens of a new OAuth connection.
   *
   * This is the connect path. A refresh uses saveRotatedTokens(), which
   * writes no config. Connecting clears a recorded refusal and stamps this
   * site's fingerprint.
   */
  public function persistTokens(array $token_data): void {
    if (empty($token_data['access_token'])) {
      throw new \InvalidArgumentException('persistTokens called with no access_token');
    }
    $has_refresh = !empty($token_data['refresh_token']);

    $this->tokenStore->saveTokens($token_data);
    $stale = [
      OAuthTokenStore::REFUSAL,
      OAuthTokenStore::RETRY_AFTER,
      OAuthTokenStore::CRON_RETRY_AFTER,
      OAuthTokenStore::PENDING,
      OAuthTokenStore::LOGGED,
    ];
    if (!$has_refresh) {
      // A refresh token from an earlier connection belongs to another chain.
      $stale[] = OAuthTokenStore::REFRESH_TOKEN;
    }
    $this->tokenStore->delete($stale);
    $this->tokenStore->set(OAuthTokenStore::FINGERPRINT, $this->getSiteFingerprint());

    $this->ensureKeyEntity(self::ACCESS_KEY_ID, 'access', 'Quant Cloud — OAuth Access Token');
    if ($has_refresh) {
      $this->ensureKeyEntity(self::REFRESH_KEY_ID, 'refresh', 'Quant Cloud — OAuth Refresh Token');
    }

    $config = $this->configFactory->getEditable('ai_provider_quant_cloud.settings');
    $config->set('auth.method', 'oauth');
    $config->set('auth.access_token_key', self::ACCESS_KEY_ID);
    $config->set('auth.refresh_token_key', $has_refresh ? self::REFRESH_KEY_ID : NULL);
    $config->save();
  }

  /**
   * Remove the OAuth connection: the tokens, their Keys and the settings.
   */
  public function disconnect(): void {
    foreach ([self::ACCESS_KEY_ID, self::REFRESH_KEY_ID] as $key_id) {
      $key = $this->keyRepository->getKey($key_id);
      if ($key) {
        $key->delete();
      }
    }
    $this->tokenStore->clear();

    $config = $this->configFactory->getEditable('ai_provider_quant_cloud.settings');
    $config->set('auth.method', 'manual');
    $config->set('auth.access_token_key', NULL);
    $config->set('auth.refresh_token_key', NULL);
    $config->save();
  }

  /**
   * Make sure a Key entity points at the token store.
   *
   * An existing Key is changed in place, so its UUID and the config that
   * depends on it stay.
   *
   * @param string $key_id
   *   The Key entity ID.
   * @param string $token
   *   Which token the Key points at: 'access' or 'refresh'.
   * @param string $label
   *   The label for a new Key.
   */
  public function ensureKeyEntity(string $key_id, string $token, string $label): void {
    $storage = $this->entityTypeManager->getStorage('key');
    $storage->resetCache([$key_id]);
    $key = $storage->load($key_id);
    if ($key instanceof KeyInterface) {
      if (!$this->isPointerKey($key)) {
        $this->pointKeyAtStore($key, $token);
        $key->save();
      }
      return;
    }
    $storage->create([
      'id' => $key_id,
      'label' => $label,
      'description' => 'Auto-generated by the Quant Cloud AI provider. It points at a token that is stored outside configuration. Do not edit manually.',
      'key_type' => 'authentication',
      'key_provider' => self::KEY_PROVIDER_ID,
      'key_provider_settings' => ['token' => $token],
      'key_input' => 'none',
    ])->save();
  }

  /**
   * Change a Key to the pointer provider. The caller saves it.
   */
  protected function pointKeyAtStore(KeyInterface $key, string $token): void {
    $key->setPlugin('key_provider', self::KEY_PROVIDER_ID);
    $key->set('key_provider_settings', ['token' => $token]);
    $key->setPlugin('key_input', 'none');
    $key->set('key_input_settings', []);
  }

  /**
   * Copy a module Key's stored value into an empty token store slot.
   *
   * Called before a module Key is deleted or changed, so that a config
   * import that runs before "drush updb" does not destroy the token.
   */
  public function rescueKeyValue(KeyInterface $key): void {
    $name = self::MODULE_KEY_STORE_NAMES[$key->id()] ?? NULL;
    if ($name === NULL || $this->isPointerKey($key) || $this->storedToken($name) !== NULL) {
      return;
    }
    $value = (string) $key->getKeyValue(TRUE);
    if ($value !== '') {
      $this->tokenStore->set($name, $value);
    }
  }

  /**
   * Keep a module Key on the pointer provider when it is saved.
   *
   * Implements the logic of hook_key_presave(). A config import of an
   * export from an earlier release would otherwise put a token value back
   * into active config.
   */
  public function protectModuleKey(KeyInterface $key): void {
    $token = self::MODULE_KEYS[$key->id()] ?? NULL;
    if ($token === NULL || $this->isPointerKey($key) || !$this->usesModuleTokens()) {
      return;
    }
    $this->rescueKeyValue($key);
    $this->pointKeyAtStore($key, $token);
  }

  /**
   * The module Key IDs that still hold a value instead of a pointer.
   *
   * @return string[]
   *   Key IDs; empty when all module Keys are pointers.
   */
  public function getUnconvertedKeyIds(): array {
    if (!$this->usesModuleTokens()) {
      return [];
    }
    $ids = [];
    foreach (array_keys(self::MODULE_KEYS) as $key_id) {
      $key = $this->keyRepository->getKey($key_id);
      if ($key instanceof KeyInterface && !$this->isPointerKey($key)) {
        $ids[] = $key_id;
      }
    }
    return $ids;
  }

  /**
   * Move tokens stored by earlier releases into the token store.
   *
   * Releases before 1.1.0 kept the token values in Key entities with the
   * 'config' provider, and the expiry in state. This keeps the Key IDs and
   * UUIDs, so settings and other code that refer to them do not change. It
   * is safe to run again, and it does not touch manual-mode Keys with other
   * IDs. It does not stamp a site fingerprint: an unstamped token counts as
   * this site's own and gets a fingerprint on the next refresh or connect.
   *
   * @return string[]
   *   What was moved, for the update hook message.
   */
  public function migrateTokenStorage(): array {
    $moved = [];
    foreach (self::MODULE_KEYS as $key_id => $token) {
      if ($this->migrateKey($key_id, $token)) {
        $moved[] = $key_id;
      }
    }
    $this->migrateStateValues();
    return $moved;
  }

  /**
   * Move one Key's value into the store and point the Key at the store.
   *
   * @return bool
   *   TRUE if the Key held a value.
   */
  protected function migrateKey(string $key_id, string $token): bool {
    $key = $this->keyRepository->getKey($key_id);
    if (!$key instanceof KeyInterface || $this->isPointerKey($key)) {
      return FALSE;
    }
    $this->rescueKeyValue($key);
    $this->pointKeyAtStore($key, $token);
    $key->save();
    return TRUE;
  }

  /**
   * Move the expiry out of state, and drop the old fingerprint.
   *
   * Without a refresh token the old expiry is dropped. Release 1.0.0 stored
   * a default one-hour expiry even for a legacy non-expiring token, and an
   * expiry that can not be refreshed would block that token.
   */
  protected function migrateStateValues(): void {
    $record = $this->tokenStore->getAll();
    $has_refresh = !empty($record[OAuthTokenStore::REFRESH_TOKEN]);
    $expires_at = (int) $this->state->get(self::LEGACY_EXPIRES_AT_STATE, 0);
    if ($has_refresh && $expires_at > 0 && empty($record[OAuthTokenStore::EXPIRES_AT])) {
      $this->tokenStore->set(OAuthTokenStore::EXPIRES_AT, $expires_at);
    }
    $this->state->deleteMultiple([self::LEGACY_EXPIRES_AT_STATE, self::LEGACY_FINGERPRINT_STATE]);
  }

  /**
   * Exchange a refresh token for a new access + refresh token pair.
   *
   * This sends the refresh token without the refresh lock, so it can replay
   * a rotated token and revoke the chain. Use getValidAccessToken() or
   * refreshFromCron().
   *
   * @param string $refresh_token
   *   The refresh token (qcr_-prefixed plaintext).
   *
   * @return array|null
   *   New token response or NULL on failure.
   *
   * @internal
   */
  public function refreshToken(string $refresh_token): ?array {
    return $this->requestRefresh($refresh_token)['tokens'];
  }

  /**
   * Send the refresh grant and classify the result.
   *
   * @return array{tokens: ?array, outcome: string, error: string, description: string}
   *   'outcome' is 'ok', 'rotated' (a peer rotated the token moments ago),
   *   'refused' (final: invalid_grant or invalid_client), 'unknown' (no
   *   answer, a 5xx, or a 200 without tokens: the portal may have rotated)
   *   or 'failed' (any other error).
   */
  protected function requestRefresh(string $refresh_token): array {
    try {
      $response = $this->httpClient->post($this->getDashboardUrl() . '/oauth/token', [
        'form_params' => [
          'grant_type' => 'refresh_token',
          'refresh_token' => $refresh_token,
          'client_id' => 'drupal-ai-provider',
        ],
        'timeout' => self::REFRESH_HTTP_TIMEOUT,
        'http_errors' => FALSE,
      ]);
    }
    catch (\Exception $e) {
      // A timeout can occur after the portal committed the rotation, so the
      // outcome is unknown. Keep the stored tokens.
      $this->logger->error('OAuth token refresh request failed (@class); the outcome is unknown. The stored tokens are kept.', [
        '@class' => get_class($e),
      ]);
      return ['tokens' => NULL, 'outcome' => 'unknown', 'error' => '', 'description' => ''];
    }

    return $this->parseRefreshResponse($response);
  }

  /**
   * Turn a refresh-grant response into a result array.
   *
   * @return array{tokens: ?array, outcome: string, error: string, description: string}
   *   See requestRefresh().
   */
  protected function parseRefreshResponse(ResponseInterface $response): array {
    $status = $response->getStatusCode();
    $data = json_decode((string) $response->getBody(), TRUE);
    $data = is_array($data) ? $data : [];

    if ($status === 200 && !empty($data['access_token'])) {
      return ['tokens' => $data, 'outcome' => 'ok', 'error' => '', 'description' => ''];
    }

    // The error fields are fixed portal strings, not secrets.
    $error = (string) ($data['error'] ?? '');
    $description = mb_substr((string) ($data['error_description'] ?? ''), 0, 200);
    $outcome = $this->classifyRefreshError($status, $error, $description);
    if ($outcome !== 'refused') {
      $this->logger->log($outcome === 'rotated' ? LogLevel::NOTICE : LogLevel::ERROR, 'OAuth token refresh rejected with status @status (@error: @description). The stored tokens are kept.', [
        '@status' => $status,
        '@error' => $error ?: 'none',
        '@description' => $description ?: 'none',
      ]);
    }
    return ['tokens' => NULL, 'outcome' => $outcome, 'error' => $error, 'description' => $description];
  }

  /**
   * Classify a refresh error as 'rotated', 'refused', 'unknown' or 'failed'.
   */
  protected function classifyRefreshError(int $status, string $error, string $description): string {
    if ($error === 'invalid_grant' && stripos($description, 'just rotated') !== FALSE) {
      return 'rotated';
    }
    if (in_array($error, ['invalid_grant', 'invalid_client'], TRUE)) {
      return 'refused';
    }
    return $status >= 500 || $status === 200 ? 'unknown' : 'failed';
  }

  /**
   * Return the current access token, refreshing it transparently if expired.
   *
   * The portal rotates the refresh token on every use and revokes the whole
   * chain when an old one is replayed, so only the holder of the refresh lock
   * may send a refresh token, and it saves the rotated token before it
   * releases the lock. A failed refresh never deletes the stored tokens.
   *
   * Cron refreshes long before expiry (refreshFromCron()); the refresh here
   * is the fallback for sites where cron does not run.
   *
   * @return string|null
   *   The access token, or NULL if no usable token can be obtained.
   */
  public function getValidAccessToken(): ?string {
    if (!$this->usesModuleTokens()) {
      return $this->getAccessToken();
    }

    $record = $this->tokenStore->getAll();
    if (empty($record[OAuthTokenStore::ACCESS_TOKEN])) {
      return $this->readUnmigratedKeyToken();
    }

    $decision = $this->getRefreshDecision($record, self::EXPIRY_MARGIN, FALSE);
    if ($decision === 'none') {
      return $record[OAuthTokenStore::ACCESS_TOKEN];
    }
    if ($decision === 'defer') {
      return $this->getUsableAccessToken($record);
    }
    return $this->refreshIfAllowed($record, self::EXPIRY_MARGIN);
  }

  /**
   * Refresh the tokens from cron when fewer than 45 days remain.
   *
   * For a short-lived token the margin is half its lifetime, so cron does
   * not refresh on every run.
   */
  public function refreshFromCron(): void {
    if (!$this->usesModuleTokens()) {
      return;
    }
    $record = $this->tokenStore->getAll();
    $margin = $this->getCronMargin($record);
    if (empty($record[OAuthTokenStore::ACCESS_TOKEN])) {
      return;
    }
    if ($this->getRefreshDecision($record, $margin, TRUE) === 'refresh') {
      $this->refreshIfAllowed($record, $margin);
    }
  }

  /**
   * Decide whether to refresh now.
   *
   * @param array $record
   *   The stored values, from OAuthTokenStore::getAll().
   * @param int $margin
   *   The expiry margin that makes a refresh due.
   * @param bool $cron
   *   TRUE for cron, FALSE for a web or API request.
   *
   * @return string
   *   'refresh'; 'none' when no refresh is due; 'defer' when a refresh is
   *   due but backs off after an unknown outcome.
   */
  protected function getRefreshDecision(array $record, int $margin, bool $cron): string {
    if ($this->hasRecentPendingRefresh($record)) {
      // Recover a rotation that may have been lost: retry at once.
      return 'refresh';
    }
    if (!$this->isExpiring($record, $margin)) {
      return 'none';
    }
    $name = $cron ? OAuthTokenStore::CRON_RETRY_AFTER : OAuthTokenStore::RETRY_AFTER;
    return (int) ($record[$name] ?? 0) > time() ? 'defer' : 'refresh';
  }

  /**
   * Whether one refresh of the stored refresh token has an unknown outcome.
   *
   * Only a marker younger than PENDING_WINDOW counts; the portal re-issues
   * a lost rotation within five minutes. The recovery is tried once: after
   * a second unknown outcome, the backoff applies.
   */
  protected function hasRecentPendingRefresh(array $record): bool {
    $pending = $record[OAuthTokenStore::PENDING] ?? NULL;
    $refresh = (string) ($record[OAuthTokenStore::REFRESH_TOKEN] ?? '');
    if (!is_array($pending) || $refresh === '' || (int) ($pending['attempts'] ?? 1) >= 2) {
      return FALSE;
    }
    if ((int) ($pending['since'] ?? 0) < time() - self::PENDING_WINDOW) {
      return FALSE;
    }
    return hash_equals((string) ($pending['token'] ?? ''), hash('sha256', $refresh));
  }

  /**
   * The cron refresh margin for the stored token, in seconds.
   */
  protected function getCronMargin(array $record): int {
    $lifetime = (int) ($record[OAuthTokenStore::LIFETIME] ?? 0);
    return $lifetime > 0 ? min(self::CRON_MARGIN, intdiv($lifetime, 2)) : self::CRON_MARGIN;
  }

  /**
   * After a 401 from the API, return a newer stored token to retry with.
   *
   * A long-running process (for example a queue worker) can hold a token
   * that a peer has since rotated.
   *
   * @param string $used_token
   *   The token that the API refused.
   *
   * @return string|null
   *   A different stored token, or NULL when there is none.
   */
  public function getTokenAfterUnauthorized(string $used_token): ?string {
    $token = $this->getAccessToken(TRUE);
    return $token && !hash_equals($used_token, $token) ? $token : NULL;
  }

  /**
   * Refresh, unless a stored condition forbids it.
   *
   * @param array $record
   *   The stored values, from OAuthTokenStore::getAll().
   * @param int $margin
   *   The expiry margin that makes a refresh due.
   */
  protected function refreshIfAllowed(array $record, int $margin): ?string {
    $blocker = $this->getRefreshBlocker($record);
    if ($blocker !== NULL) {
      $this->logBlockedRefresh($blocker);
      return $this->getUsableAccessToken($record);
    }
    if ($this->database->inTransaction()) {
      // A read inside the transaction can be stale, the lock row is not
      // visible to other processes until commit, and a rollback would
      // discard the saved pair. Cron or the next request refreshes.
      return $this->getUsableAccessToken($record);
    }

    $seen_refresh = (string) $record[OAuthTokenStore::REFRESH_TOKEN];
    if (!$this->acquireRefreshLock($margin)) {
      return $this->getUsableAccessToken($this->tokenStore->getAll());
    }

    try {
      return $this->refreshUnderLock($seen_refresh, $margin);
    }
    finally {
      $this->lock->release(self::REFRESH_LOCK_ID);
    }
  }

  /**
   * Why a refresh must not be sent, if it must not.
   *
   * @return string|null
   *   One of the RECONNECT_* constants, or NULL.
   */
  protected function getRefreshBlocker(array $record): ?string {
    if (empty($record[OAuthTokenStore::REFRESH_TOKEN])) {
      return self::RECONNECT_LEGACY_TOKEN;
    }
    if ($this->isRecordFromAnotherSite($record)) {
      return self::RECONNECT_OTHER_SITE;
    }
    return empty($record[OAuthTokenStore::REFUSAL]) ? NULL : self::RECONNECT_REFUSED;
  }

  /**
   * Log, once, why a due refresh was not sent.
   */
  protected function logBlockedRefresh(string $blocker): void {
    if ($blocker === self::RECONNECT_OTHER_SITE) {
      $this->logOnce('other_site', LogLevel::WARNING, 'The stored Quant Cloud OAuth tokens were issued to another site, for example before a database copy (different: @components). This site does not refresh them. Reconnect this site, or, only if this is the original site, confirm it on the Quant Cloud settings form.', [
        '@components' => implode(', ', $this->getFingerprintDifferences()),
      ]);
      return;
    }
    if ($blocker === self::RECONNECT_LEGACY_TOKEN) {
      $this->logOnce('legacy_token', LogLevel::WARNING, 'The Quant Cloud access token expires and no refresh token is stored. Reconnect Quant Cloud to switch to refreshable tokens.');
    }
    // A refusal is logged once when it is recorded.
  }

  /**
   * Acquire the refresh lock, waiting once for a peer that holds it.
   *
   * @return bool
   *   TRUE only when this request holds the lock. FALSE when a peer finished
   *   the refresh during the wait, or when the lock is still unavailable.
   */
  protected function acquireRefreshLock(int $margin): bool {
    if ($this->lock->acquire(self::REFRESH_LOCK_ID, self::REFRESH_LOCK_TIMEOUT)) {
      return TRUE;
    }

    $this->lock->wait(self::REFRESH_LOCK_ID, self::REFRESH_LOCK_WAIT);
    if (!$this->isExpiring($this->tokenStore->getAll(), $margin)) {
      return FALSE;
    }

    return $this->lock->acquire(self::REFRESH_LOCK_ID, self::REFRESH_LOCK_TIMEOUT);
  }

  /**
   * Refresh the tokens. The caller must hold the refresh lock.
   *
   * @param string $seen_refresh
   *   The refresh token that this request read before it took the lock. If
   *   the stored one differs now, a peer already refreshed.
   * @param int $margin
   *   The expiry margin that makes a refresh due.
   */
  protected function refreshUnderLock(string $seen_refresh, int $margin): ?string {
    $record = $this->tokenStore->getAll();
    if (!$this->isRefreshStillNeeded($seen_refresh, $record, $margin)) {
      return $this->getUsableAccessToken($record);
    }

    $result = $this->requestRefreshWithRecovery((string) $record[OAuthTokenStore::REFRESH_TOKEN]);
    if ($result['tokens']) {
      return $this->saveRotatedTokens($result['tokens']);
    }
    if ($result['outcome'] === 'refused') {
      $this->recordRefusal($result['error'], $result['description']);
    }

    // No new tokens: a peer rotated the token, the outcome is unknown, or the
    // portal refused it. Use what is stored while it has not expired.
    return $this->getUsableAccessToken($this->tokenStore->getAll());
  }

  /**
   * Whether, under the lock, the refresh must still be sent.
   *
   * @param string $seen_refresh
   *   The refresh token read before the lock.
   * @param array $record
   *   The stored values, read under the lock.
   * @param int $margin
   *   The expiry margin that makes a refresh due.
   */
  protected function isRefreshStillNeeded(string $seen_refresh, array $record, int $margin): bool {
    $refresh = (string) ($record[OAuthTokenStore::REFRESH_TOKEN] ?? '');
    if ($refresh === '' || !hash_equals($seen_refresh, $refresh)) {
      // A peer refreshed already.
      return FALSE;
    }
    if ($this->getRefreshBlocker($record) !== NULL) {
      return FALSE;
    }
    return $this->isExpiring($record, $margin) || $this->hasRecentPendingRefresh($record);
  }

  /**
   * Send the refresh grant; in CLI, retry once after an unknown outcome.
   *
   * The portal can commit a rotation whose answer never arrives. Within
   * five minutes, it answers the same (previous) refresh token once more
   * with a new pair. Cron and drush wait 15 s and retry in-process. A web
   * request does not wait: the pending marker makes the next process (web
   * or cron) retry at once. The caller holds the lock.
   */
  protected function requestRefreshWithRecovery(string $refresh_token): array {
    $this->keepRunning();
    $result = $this->sendRefresh($refresh_token);
    if ($result['outcome'] === 'unknown' && $this->isCli()) {
      $this->pause(self::RECOVERY_DELAY);
      $result = $this->sendRefresh($refresh_token);
    }
    if ($result['outcome'] === 'unknown') {
      $this->tokenStore->setMany([
        OAuthTokenStore::RETRY_AFTER => time() + self::REQUEST_BACKOFF,
        OAuthTokenStore::CRON_RETRY_AFTER => time() + self::CRON_BACKOFF,
      ]);
    }
    return $result;
  }

  /**
   * Send the refresh grant with a pending marker around it.
   *
   * The marker stays after an unknown outcome, and after a success until
   * the new pair is saved. It also stays if the process dies.
   */
  protected function sendRefresh(string $refresh_token): array {
    $hash = hash('sha256', $refresh_token);
    $pending = $this->tokenStore->get(OAuthTokenStore::PENDING);
    $same_token = is_array($pending) && hash_equals((string) ($pending['token'] ?? ''), $hash);
    $this->tokenStore->set(OAuthTokenStore::PENDING, [
      'since' => time(),
      'token' => $hash,
      'attempts' => $same_token ? (int) ($pending['attempts'] ?? 1) + 1 : 1,
    ]);
    $result = $this->requestRefresh($refresh_token);
    if (in_array($result['outcome'], ['rotated', 'refused', 'failed'], TRUE)) {
      $this->tokenStore->delete([OAuthTokenStore::PENDING]);
    }
    return $result;
  }

  /**
   * Keep the process alive while it holds a rotation in flight.
   *
   * A client disconnect or the execution time limit must not stop the
   * process between the refresh request and the save of the new pair.
   */
  protected function keepRunning(): void {
    ignore_user_abort(TRUE);
    set_time_limit(0);
  }

  /**
   * Whether this process runs from the command line (cron or drush).
   */
  protected function isCli(): bool {
    return PHP_SAPI === 'cli';
  }

  /**
   * Wait before the recovery retry.
   */
  protected function pause(int $seconds): void {
    sleep($seconds);
  }

  /**
   * Save a rotated token pair and return the new access token.
   *
   * Only the token store is written: no config, no Key entity.
   */
  protected function saveRotatedTokens(array $token_data): string {
    try {
      $this->tokenStore->saveTokens($token_data);
      $this->tokenStore->delete([
        OAuthTokenStore::PENDING,
        OAuthTokenStore::LOGGED,
        OAuthTokenStore::RETRY_AFTER,
        OAuthTokenStore::CRON_RETRY_AFTER,
      ]);
      if (!is_array($this->tokenStore->get(OAuthTokenStore::FINGERPRINT))) {
        $this->tokenStore->set(OAuthTokenStore::FINGERPRINT, $this->getSiteFingerprint());
      }
    }
    catch (\Throwable $e) {
      // The portal already rotated the refresh token. The new pair works for
      // this request, but the next refresh may fail and need a reconnect.
      $this->logger->critical('Could not save the refreshed OAuth tokens (@class). Reconnect to Quant Cloud if later requests fail.', [
        '@class' => get_class($e),
      ]);
    }
    return $token_data['access_token'];
  }

  /**
   * Record a final refusal, so that no further refresh is sent.
   */
  protected function recordRefusal(string $error, string $description): void {
    $this->tokenStore->set(OAuthTokenStore::REFUSAL, [
      'error' => $error,
      'description' => $description,
    ]);
    $this->logger->error('Quant Cloud refused the OAuth token refresh (@error: @description). Refresh attempts stop until this site reconnects to Quant Cloud.', [
      '@error' => $error,
      '@description' => $description ?: 'none',
    ]);
  }

  /**
   * Return the stored access token if it has not expired yet.
   *
   * @param array $record
   *   The stored values, from OAuthTokenStore::getAll().
   */
  protected function getUsableAccessToken(array $record): ?string {
    $access = $record[OAuthTokenStore::ACCESS_TOKEN] ?? NULL;
    if (!$access) {
      return NULL;
    }
    if ($this->isExpiring($record, 0)) {
      $this->logOnce('expired', LogLevel::WARNING, 'The Quant Cloud access token expired and was not refreshed. AI requests fail until the token is refreshed or this site reconnects to Quant Cloud.');
      return NULL;
    }
    return $access;
  }

  /**
   * Log a message once, until the next rotation or connection.
   */
  protected function logOnce(string $id, string $level, string $message, array $context = []): void {
    $logged = (array) ($this->tokenStore->get(OAuthTokenStore::LOGGED) ?? []);
    if (in_array($id, $logged, TRUE)) {
      return;
    }
    $logged[] = $id;
    $this->tokenStore->set(OAuthTokenStore::LOGGED, $logged);
    $this->logger->log($level, $message, $context);
  }

  /**
   * Why this site must act on its Quant Cloud connection, if it must.
   *
   * @return string|null
   *   One of the RECONNECT_* constants, or NULL when no action is needed.
   */
  public function getReconnectReason(): ?string {
    if (!$this->usesModuleTokens()) {
      return NULL;
    }
    $record = $this->tokenStore->getAll();
    if (empty($record[OAuthTokenStore::ACCESS_TOKEN])) {
      return NULL;
    }
    return $this->getRefreshBlocker($record);
  }

  /**
   * The text that tells an administrator why and how to act, if needed.
   */
  public function getReconnectMessage(): ?TranslatableMarkup {
    $reason = $this->getReconnectReason();
    if ($reason === self::RECONNECT_OTHER_SITE) {
      return new TranslatableMarkup('The Quant Cloud OAuth tokens on this site were issued to another site, for example before a database copy (different: @components). This site does not refresh them. Reconnect this site to Quant Cloud before the access token expires. Only if this is the original site (for example after a settings change), confirm it with "This is the same site — keep the connection".', [
        '@components' => implode(', ', $this->getFingerprintDifferences()),
      ]);
    }
    if ($reason === self::RECONNECT_REFUSED) {
      $refusal = (array) $this->tokenStore->get(OAuthTokenStore::REFUSAL);
      return new TranslatableMarkup('Quant Cloud refused to refresh the OAuth token (@error: @description). Reconnect this site to Quant Cloud.', [
        '@error' => $refusal['error'] ?? '',
        '@description' => $refusal['description'] ?? '',
      ]);
    }
    if ($reason === self::RECONNECT_LEGACY_TOKEN) {
      return new TranslatableMarkup('Reconnect Quant Cloud to switch to refreshable tokens. This site uses an older OAuth token that does not refresh, and Quant Cloud will retire these tokens.');
    }
    return NULL;
  }

  /**
   * Accept the stored tokens as this site's own, without OAuth.
   *
   * For a site whose fingerprint changed although it is the same site (for
   * example after a database server move).
   */
  public function confirmSameSite(): void {
    $this->tokenStore->set(OAuthTokenStore::FINGERPRINT, $this->getSiteFingerprint());
    $this->tokenStore->delete([OAuthTokenStore::LOGGED]);
  }

  /**
   * Whether the stored tokens were issued to a different site.
   */
  public function isTokenFromAnotherSite(): bool {
    return $this->isRecordFromAnotherSite($this->tokenStore->getAll());
  }

  /**
   * Whether a stored record carries another site's fingerprint.
   *
   * A record without a fingerprint (stored before 1.1.0, or moved by
   * update 10004) counts as this site's own. It gets a fingerprint on the
   * next refresh or connect.
   */
  protected function isRecordFromAnotherSite(array $record): bool {
    $stored = $record[OAuthTokenStore::FINGERPRINT] ?? NULL;
    if (!is_array($stored) || $stored === []) {
      return FALSE;
    }
    return $this->siteFingerprint->getDifferences($stored) !== [];
  }

  /**
   * The fingerprint components that differ from the stored fingerprint.
   *
   * @return string[]
   *   Readable component names, without values.
   */
  public function getFingerprintDifferences(): array {
    $stored = $this->tokenStore->get(OAuthTokenStore::FINGERPRINT);
    return is_array($stored) ? $this->siteFingerprint->getDifferences($stored) : [];
  }

  /**
   * This site's fingerprint: component name => hash.
   *
   * See SiteFingerprint for the components.
   * $settings['ai_provider_quant_cloud_site_id'] replaces the automatic
   * components, for environments that share them.
   *
   * @return array<string,string>
   *   One hash per component.
   */
  public function getSiteFingerprint(): array {
    return $this->siteFingerprint->compute();
  }

  /**
   * Whether the stored access token expires within the given margin.
   *
   * @param array $record
   *   The stored values, from OAuthTokenStore::getAll().
   * @param int $margin
   *   Seconds before the expiry at which the token counts as expiring.
   */
  protected function isExpiring(array $record, int $margin): bool {
    $expires_at = (int) ($record[OAuthTokenStore::EXPIRES_AT] ?? 0);
    return $expires_at > 0 && (time() + $margin) >= $expires_at;
  }

  /**
   * The dashboard base URL for the configured platform.
   */
  protected function getDashboardUrl(): string {
    $config = $this->configFactory->get('ai_provider_quant_cloud.settings');
    $platform = $config->get('platform') ?: 'quantcdn';
    $dashboard_url = $config->get("platforms.{$platform}.dashboard_url");
    if ($dashboard_url) {
      return $dashboard_url;
    }
    return $platform === 'quantgov' ? 'https://dash.quantgov.cloud' : 'https://dashboard.quantcdn.io';
  }

  /**
   * Get dashboard URL for manual token generation.
   *
   * @return string
   *   URL where users can generate an access token.
   */
  public function getTokenGenerationUrl(): string {
    $config = $this->configFactory->get('ai_provider_quant_cloud.settings');
    $platform = $config->get('platform') ?: 'quantcdn';

    $dashboard_url = $config->get("platforms.{$platform}.dashboard_url");

    // Assume there's a tokens or API settings page
    // Adjust this path based on your actual dashboard structure.
    return $dashboard_url . '/account/api-tokens';
  }

}
