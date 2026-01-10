<?php

namespace Drupal\ai_provider_quant_cloud_vdb\Plugin\VdbProvider;

use Drupal\ai\Attribute\AiVdbProvider;
use Drupal\ai\Base\AiVdbProviderClientBase;
use Drupal\ai\Enum\VdbSimilarityMetrics;
use Drupal\ai_provider_quant_cloud\Client\QuantCloudVectorDbClient;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\search_api\Query\QueryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Quant Cloud Vector Database Provider.
 *
 * Provides integration with Quant Cloud's VectorDB API for semantic search
 * and document indexing, compatible with the Drupal AI Search module.
 */
#[AiVdbProvider(
  id: 'quant_cloud_vdb',
  label: new TranslatableMarkup('Quant Cloud VectorDB'),
)]
class QuantCloudVdbProvider extends AiVdbProviderClientBase {

  /**
   * The VectorDB client.
   *
   * @var \Drupal\ai_provider_quant_cloud\Client\QuantCloudVectorDbClient
   */
  protected QuantCloudVectorDbClient $vdbClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static(
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('key.repository'),
      $container->get('event_dispatcher'),
      $container->get('entity_field.manager'),
      $container->get('messenger'),
    );
    $instance->vdbClient = $container->get('ai_provider_quant_cloud.vectordb_client');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getClient(): QuantCloudVectorDbClient {
    return $this->vdbClient;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfig(): ImmutableConfig {
    return $this->configFactory->get('ai_provider_quant_cloud.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function ping(): bool {
    try {
      $this->vdbClient->listCollections();
      return TRUE;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isSetup(): bool {
    $config = $this->getConfig();

    // Check that authentication is configured.
    if (!$config->get('auth.access_token_key') || !$config->get('auth.organization_id')) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getCollections(string $database = 'default'): array {
    try {
      $response = $this->vdbClient->listCollections();
      $collections = $response['collections'] ?? [];

      // Return just the collection names as expected by the interface.
      return array_map(function ($collection) {
        return $collection['name'] ?? $collection['collectionId'];
      }, $collections);
    }
    catch (\Exception $e) {
      $this->getLogger('ai_provider_quant_cloud')->error('Failed to list collections: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createCollection(
    string $collection_name,
    int $dimension,
    VdbSimilarityMetrics $metric_type = VdbSimilarityMetrics::CosineSimilarity,
    string $database = 'default',
  ): void {
    try {
      // Map the Drupal metric type to a description (Quant Cloud uses cosine).
      $description = "Drupal AI Search collection - metric: {$metric_type->value}";

      // Quant Cloud VDB derives dimension from embedding model automatically.
      // Don't pass dimension - just the model name.
      $this->vdbClient->createCollection(
        $collection_name,
        $description,
        'amazon.titan-embed-text-v2:0'
        // No dimension - API determines from model (1024 for Titan v2)
      );

      $this->getLogger('ai_provider_quant_cloud')->info('Created collection @name', [
        '@name' => $collection_name,
      ]);
    }
    catch (\Exception $e) {
      $this->getLogger('ai_provider_quant_cloud')->error('Failed to create collection: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function dropCollection(
    string $collection_name,
    string $database = 'default',
  ): void {
    try {
      $collection_id = $this->resolveCollectionId($collection_name);
      $this->vdbClient->deleteCollection($collection_id);

      $this->getLogger('ai_provider_quant_cloud')->info('Deleted collection @name', [
        '@name' => $collection_name,
      ]);
    }
    catch (\Exception $e) {
      $this->getLogger('ai_provider_quant_cloud')->error('Failed to delete collection: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function insertIntoCollection(
    string $collection_name,
    array $data,
    string $database = 'default',
  ): void {
    $collection_id = $this->resolveCollectionId($collection_name);

    // The AI Search module sends data with 'vector', 'drupal_long_id', 'drupal_entity_id'.
    // We need to store vectors with content.
    $documents = [];

    // Build metadata from the data array.
    $metadata = [];
    if (!empty($data['drupal_long_id'])) {
      $metadata['drupal_long_id'] = $data['drupal_long_id'];
    }
    if (!empty($data['drupal_entity_id'])) {
      $metadata['drupal_entity_id'] = $data['drupal_entity_id'];
    }
    if (!empty($data['server_id'])) {
      $metadata['server_id'] = $data['server_id'];
    }
    if (!empty($data['index_id'])) {
      $metadata['index_id'] = $data['index_id'];
    }

    // Create document with pre-computed vector.
    $document = [
      'content' => $data['drupal_long_id'] ?? 'indexed_content',
      'metadata' => $metadata,
    ];

    // If vector is provided, include it for pre-computed embedding.
    if (!empty($data['vector'])) {
      $document['vector'] = $data['vector'];
    }

    $documents[] = $document;

    try {
      $response = $this->vdbClient->uploadDocuments($collection_id, $documents);

      // Store ID mapping in state for later retrieval.
      $document_ids = $response['documentIds'] ?? [];
      if (!empty($document_ids) && !empty($data['drupal_long_id'])) {
        $state_key = "ai_provider_quant_cloud.vdb_mapping.{$collection_name}";
        $mapping = \Drupal::state()->get($state_key, []);
        $mapping[$data['drupal_long_id']] = $document_ids[0];
        \Drupal::state()->set($state_key, $mapping);
      }

      $this->getLogger('ai_provider_quant_cloud')->debug('Inserted document into collection @collection', [
        '@collection' => $collection_name,
      ]);
    }
    catch (\Exception $e) {
      $this->getLogger('ai_provider_quant_cloud')->error('Failed to insert document: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteFromCollection(
    string $collection_name,
    array $ids,
    string $database = 'default',
  ): void {
    // Note: Individual document deletion requires API enhancement.
    // For now, log a warning.
    $this->getLogger('ai_provider_quant_cloud')->warning(
      'Individual document deletion not yet supported. Collection: @collection, IDs: @ids',
      [
        '@collection' => $collection_name,
        '@ids' => implode(', ', $ids),
      ]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function querySearch(
    string $collection_name,
    array $output_fields,
    string $filters = '',
    int $limit = 10,
    int $offset = 0,
    string $database = 'default',
  ): array {
    // Query search without vector is not supported.
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function vectorSearch(
    string $collection_name,
    array $vector_input,
    array $output_fields,
    QueryInterface $query,
    string $filters = '',
    int $limit = 10,
    int $offset = 0,
    string $database = 'default',
  ): array {
    $collection_id = $this->resolveCollectionId($collection_name);

    // Get the first vector from the input.
    $vector = reset($vector_input);
    if (!$vector) {
      return [];
    }

    try {
      $response = $this->vdbClient->queryByVector(
        $collection_id,
        $vector,
        $limit,
        0.0,
        FALSE
      );

      // Map API response to expected format.
      $results = [];
      foreach ($response['results'] ?? [] as $result) {
        $metadata = $result['metadata'] ?? [];
        $results[] = [
          'id' => $metadata['drupal_long_id'] ?? $result['documentId'],
          'drupal_entity_id' => $metadata['drupal_entity_id'] ?? NULL,
          'score' => $result['score'] ?? 0.0,
        ];
      }

      return $results;
    }
    catch (\Exception $e) {
      $this->getLogger('ai_provider_quant_cloud')->error('Vector search failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getVdbIds(
    string $collection_name,
    array $drupalIds,
    string $database = 'default',
  ): array {
    // Retrieve ID mapping from state.
    $state_key = "ai_provider_quant_cloud.vdb_mapping.{$collection_name}";
    $mapping = \Drupal::state()->get($state_key, []);

    $vdb_ids = [];
    foreach ($drupalIds as $drupal_id) {
      if (isset($mapping[$drupal_id])) {
        $vdb_ids[] = $mapping[$drupal_id];
      }
    }

    return $vdb_ids;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareFilters(QueryInterface $query): string {
    // Quant Cloud VDB doesn't support metadata filtering yet.
    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function getRawEmbeddingFieldName(): ?string {
    return 'embedding';
  }

  /**
   * Resolve a collection name to its UUID.
   *
   * @param string $collection_name
   *   Collection name or UUID.
   *
   * @return string
   *   The collection UUID.
   *
   * @throws \RuntimeException
   *   If collection cannot be resolved.
   */
  protected function resolveCollectionId(string $collection_name): string {
    // If it looks like a UUID, use directly.
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $collection_name)) {
      return $collection_name;
    }

    // Search collections by name.
    try {
      $response = $this->vdbClient->listCollections();
      foreach ($response['collections'] ?? [] as $collection) {
        if (($collection['name'] ?? '') === $collection_name) {
          return $collection['collectionId'];
        }
      }
    }
    catch (\Exception $e) {
      // Fall through to exception.
    }

    throw new \RuntimeException("Collection not found: {$collection_name}");
  }

  /**
   * {@inheritdoc}
   */
  public function buildSettingsForm(
    array $form,
    FormStateInterface $form_state,
    array $configuration,
  ): array {
    $form = parent::buildSettingsForm($form, $form_state, $configuration);

    // Hide database_name - not relevant for Quant Cloud (uses org from config).
    $form['database_name']['#type'] = 'hidden';
    $form['database_name']['#value'] = 'quant_cloud';

    // Hide metric selector - Quant Cloud uses cosine similarity only.
    if (isset($form['metric'])) {
      $form['metric']['#type'] = 'hidden';
      $form['metric']['#value'] = 'cosine';
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function viewIndexSettings(array $database_settings): array {
    return [
      'database' => [
        '#type' => 'item',
        '#title' => $this->t('Database'),
        '#markup' => $database_settings['database_name'] ?? 'quant_cloud',
      ],
      'collection' => [
        '#type' => 'item',
        '#title' => $this->t('Collection'),
        '#markup' => $database_settings['collection'] ?? 'Not configured',
      ],
    ];
  }

}
