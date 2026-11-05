<?php

declare(strict_types=1);

namespace Drupal\ai_provider_quant_cloud\Plugin\KeyProvider;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_provider_quant_cloud\Service\OAuthTokenStore;
use Drupal\key\Attribute\KeyProvider;
use Drupal\key\KeyInterface;
use Drupal\key\Plugin\KeyProviderBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reads a Quant Cloud OAuth token from the module's key_value store.
 *
 * The Key entity holds only a pointer ('token' => 'access' or 'refresh'),
 * so config export contains no secret and config import cannot change the
 * value.
 *
 * @KeyProvider(
 *   id = "quant_cloud_oauth",
 *   label = @Translation("Quant Cloud OAuth"),
 *   description = @Translation("Reads an OAuth token that the Quant Cloud AI provider stores outside configuration."),
 *   tags = {},
 *   key_value = {
 *     "accepted" = FALSE,
 *     "required" = FALSE
 *   }
 * )
 */
#[KeyProvider(
  id: 'quant_cloud_oauth',
  label: new TranslatableMarkup('Quant Cloud OAuth'),
  description: new TranslatableMarkup('Reads an OAuth token that the Quant Cloud AI provider stores outside configuration.'),
  tags: [],
  key_value: [
    'accepted' => FALSE,
    'required' => FALSE,
  ],
)]
class QuantCloudOAuthKeyProvider extends KeyProviderBase {

  /**
   * The token store.
   */
  protected OAuthTokenStore $tokenStore;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->tokenStore = $container->get('ai_provider_quant_cloud.token_store');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['token' => 'access'];
  }

  /**
   * {@inheritdoc}
   */
  public function getKeyValue(KeyInterface $key) {
    $name = ($this->getConfiguration()['token'] ?? 'access') === 'refresh'
      ? OAuthTokenStore::REFRESH_TOKEN
      : OAuthTokenStore::ACCESS_TOKEN;
    return (string) ($this->tokenStore->get($name) ?? '');
  }

}
