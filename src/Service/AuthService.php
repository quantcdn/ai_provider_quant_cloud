<?php

namespace Drupal\ai_provider_quant_cloud\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\ChainedFastBackend;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LogLevel;

/**
 * Service for managing authentication with Quant Cloud API.
 */
class AuthService {

  /**
   * Key entity ID that holds the OAuth access token.
   */
  public const ACCESS_KEY_ID = 'quant_cloud_oauth_access_token';

  /**
   * Key entity ID that holds the OAuth refresh token.
   */
  public const REFRESH_KEY_ID = 'quant_cloud_oauth_refresh_token';

  /**
   * State key that holds the absolute access-token expiry (Unix time).
   */
  public const EXPIRES_AT_STATE = 'quant_cloud_oauth_expires_at';

  /**
   * State key that holds the fingerprint of the site that owns the tokens.
   */
  public const FINGERPRINT_STATE = 'quant_cloud_oauth_site_fingerprint';

  /**
   * Reconnect reason: the stored tokens belong to another site.
   */
  public const RECONNECT_OTHER_SITE = 'other_site';

  /**
   * Reconnect reason: OAuth is connected but no refresh token is stored.
   */
  public const RECONNECT_LEGACY_TOKEN = 'legacy_token';

  /**
   * Lock name that serializes refresh-token use across processes.
   */
  protected const REFRESH_LOCK_ID = 'ai_provider_quant_cloud.oauth_refresh';

  /**
   * Lock lifetime. It must be longer than the refresh HTTP timeout.
   */
  protected const REFRESH_LOCK_TIMEOUT = 90;

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
   * Seconds before expiry at which the access token is refreshed.
   */
  protected const EXPIRY_MARGIN = 60;

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
   * The state service.
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
   * The config cache bin, reset before the tokens are re-read.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface|null
   */
  protected $configCache;

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
    ?CacheBackendInterface $config_cache = NULL,
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('ai_provider_quant_cloud');
    $this->keyRepository = $key_repository;
    $this->entityTypeManager = $entity_type_manager;
    $this->state = $state;
    $this->lock = $lock;
    $this->configCache = $config_cache;
  }

  /**
   * Get the stored access token from Key module, without a refresh.
   *
   * API calls must use getValidAccessToken() instead.
   *
   * @param bool $fresh
   *   TRUE to bypass the per-request Key value cache.
   *
   * @return string|null
   *   The access token or NULL if not configured.
   */
  public function getAccessToken(bool $fresh = FALSE): ?string {
    return $this->readTokenKey('auth.access_token_key', $fresh);
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
   * Persist a token response into Drupal Key + state.
   *
   * Stores access + refresh tokens as Key entities, plus the absolute
   * access-token expiry timestamp in Drupal state. Flips the module to
   * OAuth mode so subsequent calls use the OAuth-issued credentials.
   *
   * The write order matters when a write fails part way. The rotated refresh
   * token is saved first: the portal has already invalidated the previous
   * one, so losing it would break the chain. The expiry is saved after the
   * access token, so a reader that sees the new expiry also sees the new
   * access token.
   */
  public function persistTokens(array $token_data): void {
    if (empty($token_data['access_token'])) {
      throw new \InvalidArgumentException('persistTokens called with no access_token');
    }

    $has_refresh = !empty($token_data['refresh_token']);
    if ($has_refresh) {
      $this->writeKey(self::REFRESH_KEY_ID, $token_data['refresh_token'], 'Quant Cloud — OAuth Refresh Token');
    }

    $this->writeKey(self::ACCESS_KEY_ID, $token_data['access_token'], 'Quant Cloud — OAuth Access Token');
    $this->writeExpiry($token_data['expires_in'] ?? NULL);
    $this->state->set(self::FINGERPRINT_STATE, $this->getSiteFingerprint());

    $config = $this->configFactory->getEditable('ai_provider_quant_cloud.settings');
    $config->set('auth.method', 'oauth');
    $config->set('auth.access_token_key', self::ACCESS_KEY_ID);
    if ($has_refresh) {
      $config->set('auth.refresh_token_key', self::REFRESH_KEY_ID);
    }
    $config->save();
  }

  /**
   * Store the absolute access-token expiry, or clear it when unknown.
   */
  protected function writeExpiry(mixed $expires_in): void {
    if ($expires_in === NULL) {
      $this->state->delete(self::EXPIRES_AT_STATE);
      return;
    }
    $this->state->set(self::EXPIRES_AT_STATE, time() + (int) $expires_in);
  }

  /**
   * Create a Key entity holding a token value, or update it in place.
   *
   * The existing Key is never deleted. A delete-then-create leaves a gap in
   * which a concurrent request finds no token and tells the user to
   * re-authenticate.
   */
  protected function writeKey(string $key_id, string $value, string $label): void {
    $storage = $this->entityTypeManager->getStorage('key');
    $storage->resetCache([$key_id]);
    $existing = $storage->load($key_id);
    if ($existing instanceof KeyInterface) {
      $existing->setKeyValue($value);
      $existing->save();
      return;
    }

    $key = $storage->create([
      'id' => $key_id,
      'label' => $label,
      'description' => 'Auto-generated by the Quant Cloud AI provider during OAuth connection. Do not edit manually.',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_input' => 'text_field',
      'key_provider_settings' => [
        'key_value' => $value,
      ],
    ]);
    $key->save();
  }

  /**
   * Get the current refresh token, or NULL if none stored.
   *
   * @param bool $fresh
   *   TRUE to bypass the per-request Key value cache.
   */
  protected function getRefreshToken(bool $fresh = FALSE): ?string {
    return $this->readTokenKey('auth.refresh_token_key', $fresh);
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
   * Exchange a refresh token for a new access + refresh token pair.
   *
   * Uses the public-client form (no client_secret).
   *
   * @param string $refresh_token
   *   The refresh token (qcr_-prefixed plaintext).
   *
   * @return array|null
   *   New token response or NULL on failure.
   */
  public function refreshToken(string $refresh_token): ?array {
    return $this->requestRefresh($refresh_token)['tokens'];
  }

  /**
   * Send the refresh grant and classify the result.
   *
   * @return array{tokens: ?array, rotated: bool}
   *   'tokens' is the new token response, or NULL on failure. 'rotated' is
   *   TRUE when the portal says a peer rotated this refresh token moments ago.
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
      return ['tokens' => NULL, 'rotated' => FALSE];
    }

    return $this->parseRefreshResponse($response);
  }

  /**
   * Turn a refresh-grant response into a result array.
   *
   * @return array{tokens: ?array, rotated: bool}
   *   See requestRefresh().
   */
  protected function parseRefreshResponse(ResponseInterface $response): array {
    $status = $response->getStatusCode();
    $data = json_decode((string) $response->getBody(), TRUE);
    $data = is_array($data) ? $data : [];

    if ($status === 200 && !empty($data['access_token'])) {
      return ['tokens' => $data, 'rotated' => FALSE];
    }

    $error = (string) ($data['error'] ?? '');
    $description = (string) ($data['error_description'] ?? '');
    $rotated = $status === 400 && $error === 'invalid_grant' && stripos($description, 'just rotated') !== FALSE;

    // The error fields are fixed portal strings, not secrets.
    $this->logger->log($rotated ? LogLevel::NOTICE : LogLevel::ERROR, 'OAuth token refresh rejected with status @status (@error: @description). The stored tokens are kept.', [
      '@status' => $status,
      '@error' => $error ?: 'none',
      '@description' => mb_substr($description, 0, 200) ?: 'none',
    ]);

    return ['tokens' => NULL, 'rotated' => $rotated];
  }

  /**
   * Return the current access token, refreshing it transparently if expired.
   *
   * Refresh window is "expired or expiring within 60 seconds" so a near-expiry
   * token doesn't get used for a long-running request. The portal rotates the
   * refresh token on every use and revokes the whole chain when an old one is
   * replayed, so only the holder of the refresh lock may send a refresh
   * token, and it saves the rotated token before it releases the lock.
   *
   * A failed refresh never deletes the stored tokens.
   *
   * @return string|null
   *   The access token, or NULL if no usable token can be obtained.
   */
  public function getValidAccessToken(): ?string {
    $access = $this->getAccessToken();
    if (!$access || !$this->isOauthMode()) {
      return $access;
    }

    if ($this->isTokenFromAnotherSite()) {
      $this->logger->warning('The stored Quant Cloud OAuth tokens belong to another site, for example after a database copy. They are not used or refreshed. Reconnect this site to Quant Cloud.');
      return NULL;
    }

    if (!$this->isAccessTokenExpiring()) {
      return $access;
    }

    if (!$this->acquireRefreshLock()) {
      return $this->getStoredUsableAccessToken();
    }

    try {
      return $this->refreshUnderLock();
    }
    finally {
      $this->lock->release(self::REFRESH_LOCK_ID);
    }
  }

  /**
   * Acquire the refresh lock, waiting once for a peer that holds it.
   *
   * @return bool
   *   TRUE only when this request holds the lock. FALSE when a peer finished
   *   the refresh during the wait, or when the lock is still unavailable.
   */
  protected function acquireRefreshLock(): bool {
    if ($this->lock->acquire(self::REFRESH_LOCK_ID, self::REFRESH_LOCK_TIMEOUT)) {
      return TRUE;
    }

    $this->lock->wait(self::REFRESH_LOCK_ID, self::REFRESH_LOCK_WAIT);
    $this->resetTokenCaches();
    if (!$this->isAccessTokenExpiring()) {
      return FALSE;
    }

    return $this->lock->acquire(self::REFRESH_LOCK_ID, self::REFRESH_LOCK_TIMEOUT);
  }

  /**
   * Refresh the tokens. The caller must hold the refresh lock.
   *
   * The refresh token is read here, from fresh storage, and not before the
   * lock: a peer may have rotated it while this request waited.
   */
  protected function refreshUnderLock(): ?string {
    $this->resetTokenCaches();
    if (!$this->isAccessTokenExpiring()) {
      return $this->getAccessToken(TRUE);
    }

    $refresh = $this->getRefreshToken(TRUE);
    if (!$refresh) {
      $this->logger->warning('Access token expired and no refresh token is stored — re-authentication required.');
      return NULL;
    }

    $result = $this->requestRefresh($refresh);
    if ($result['tokens']) {
      return $this->saveRefreshedTokens($result['tokens']);
    }

    // The refresh did not give new tokens: a peer rotated the token, the
    // outcome is unknown, or the portal refused it. Use what is stored (a
    // peer may have saved a new pair) while it has not expired.
    $this->resetTokenCaches();
    return $this->getStoredUsableAccessToken();
  }

  /**
   * Persist a refreshed token pair and return the new access token.
   */
  protected function saveRefreshedTokens(array $token_data): string {
    try {
      $this->persistTokens($token_data);
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
   * Return the stored access token if it has not expired yet.
   *
   * The caller must reset the token caches first.
   */
  protected function getStoredUsableAccessToken(): ?string {
    if ($this->isAccessTokenExpiring(0)) {
      $this->logger->warning('The Quant Cloud access token expired while another process refreshed it. Retry the request.');
      return NULL;
    }
    return $this->getAccessToken(TRUE);
  }

  /**
   * Discard the cached copies of the settings, the token Keys and state.
   *
   * A peer process can rotate the tokens at any time. Before the refresh
   * token is used, every cache layer that can hold an old copy is reset.
   */
  protected function resetTokenCaches(): void {
    if ($this->configCache instanceof ChainedFastBackend) {
      // The fast (APCu) bin trusts a write timestamp read once per request.
      $this->configCache->reset();
    }
    $this->configFactory->reset('ai_provider_quant_cloud.settings');
    $config = $this->configFactory->get('ai_provider_quant_cloud.settings');
    $key_ids = array_filter([
      $config->get('auth.access_token_key'),
      $config->get('auth.refresh_token_key'),
    ]);
    foreach ($key_ids as $key_id) {
      $this->configFactory->reset('key.key.' . $key_id);
    }
    if ($key_ids) {
      $this->entityTypeManager->getStorage('key')->resetCache(array_values($key_ids));
    }
    $this->state->resetCache();
  }

  /**
   * Why this site must reconnect to Quant Cloud, if it must.
   *
   * @return string|null
   *   One of the RECONNECT_* constants, or NULL when no reconnect is needed.
   */
  public function getReconnectReason(): ?string {
    if (!$this->isOauthMode() || !$this->getAccessToken()) {
      return NULL;
    }
    if ($this->isTokenFromAnotherSite()) {
      return self::RECONNECT_OTHER_SITE;
    }
    return $this->getRefreshToken() ? NULL : self::RECONNECT_LEGACY_TOKEN;
  }

  /**
   * The text that tells an administrator why and how to reconnect.
   *
   * @param string $reason
   *   One of the RECONNECT_* constants.
   */
  public static function reconnectMessage(string $reason): TranslatableMarkup {
    if ($reason === self::RECONNECT_OTHER_SITE) {
      return new TranslatableMarkup('The Quant Cloud OAuth tokens on this site belong to another site, for example after a database copy. This site does not use or refresh them. Reconnect this site to Quant Cloud.');
    }
    return new TranslatableMarkup('Reconnect Quant Cloud to switch to refreshable tokens. This site uses an older OAuth token that does not refresh, and Quant Cloud will retire these tokens.');
  }

  /**
   * Whether the stored tokens were issued to a different site.
   *
   * A copy of the database (for example production to staging) carries the
   * tokens and their fingerprint. The copy computes a different fingerprint,
   * so it never sends the shared refresh token. Tokens stored before the
   * fingerprint existed have none; they get one on the next refresh or
   * connect.
   */
  public function isTokenFromAnotherSite(): bool {
    $stored = $this->state->get(self::FINGERPRINT_STATE);
    if (!is_string($stored) || $stored === '') {
      return FALSE;
    }
    return !hash_equals($stored, $this->getSiteFingerprint());
  }

  /**
   * A fingerprint of this site that a database copy does not carry.
   *
   * The site UUID is in the database, so a copy keeps it. The other parts
   * come from settings.php and the database connection, which a copy to
   * another environment does not take with it. The base URL is not used:
   * drush and cron often have none, and one site can have many host names.
   * $settings['ai_provider_quant_cloud_site_id'] replaces the automatic
   * parts, for environments that share settings and database coordinates.
   */
  public function getSiteFingerprint(): string {
    $site_id = (string) Settings::get('ai_provider_quant_cloud_site_id', '');
    $parts = $site_id !== '' ? ['site_id', $site_id] : $this->getSiteIdentityParts();
    $parts[] = (string) $this->configFactory->get('system.site')->get('uuid');
    return hash('sha256', implode("\0", $parts));
  }

  /**
   * The automatic, environment-specific parts of the site fingerprint.
   *
   * The database password is left out, so a password rotation does not
   * disconnect the site.
   *
   * @return string[]
   *   The hash salt and the default database host, port, name and prefix.
   */
  protected function getSiteIdentityParts(): array {
    $db = Database::getConnectionInfo()['default'] ?? [];
    $prefix = $db['prefix'] ?? '';
    return [
      'auto',
      (string) Settings::get('hash_salt', ''),
      (string) ($db['host'] ?? ''),
      (string) ($db['port'] ?? ''),
      (string) ($db['database'] ?? ''),
      is_array($prefix) ? (string) ($prefix['default'] ?? '') : (string) $prefix,
    ];
  }

  /**
   * Whether the module uses OAuth-issued tokens (and so can refresh).
   */
  protected function isOauthMode(): bool {
    return $this->configFactory->get('ai_provider_quant_cloud.settings')->get('auth.method') === 'oauth';
  }

  /**
   * Whether the stored access token expires within the given margin.
   *
   * @param int $margin
   *   Seconds before the expiry at which the token counts as expiring.
   */
  protected function isAccessTokenExpiring(int $margin = self::EXPIRY_MARGIN): bool {
    $expires_at = (int) ($this->state->get(self::EXPIRES_AT_STATE) ?? 0);
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
