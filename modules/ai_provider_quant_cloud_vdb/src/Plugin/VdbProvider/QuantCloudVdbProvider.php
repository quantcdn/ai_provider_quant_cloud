<?php

namespace Drupal\ai_provider_quant_cloud_vdb\Plugin\VdbProvider;

use Drupal\ai\Attribute\AiVdbProvider;
use Drupal\ai\Base\AiVdbProviderClientBase;
use Drupal\ai\Enum\VdbSimilarityMetrics;
use Drupal\ai\Exception\AiUnsafePromptException;
use Drupal\ai_provider_quant_cloud\Client\QuantCloudVectorDbClient;
use Drupal\ai_search\EmbeddingStrategyInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\search_api\IndexInterface;
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
   *
   * Override to batch document uploads for efficiency.
   * Supports server-side embedding mode to bypass slow sequential Drupal embeddings.
   */
  public function indexItems(
    array $configuration,
    IndexInterface $index,
    array $items,
    EmbeddingStrategyInterface $embedding_strategy,
  ): array {
    $successfulItemIds = [];
    $documentsToUpload = [];
    $itemBase = [
      'metadata' => [
        'server_id' => $index->getServerId(),
        'index_id' => $index->id(),
      ],
    ];

    // Check if server-side embeddings are enabled (bypasses slow Drupal embedding).
    $serverSideEmbeddings = $configuration['database_settings']['server_side_embeddings'] ?? FALSE;

    $this->getLogger('ai_provider_quant_cloud')->debug('indexItems called with @count items (server_side_embeddings: @sse)', [
      '@count' => count($items),
      '@sse' => $serverSideEmbeddings ? 'yes' : 'no',
    ]);

    // Initialize embedding strategy ONCE if using server-side embeddings.
    // This sets up the converter, tokenizer, chunk sizes, etc.
    if ($serverSideEmbeddings) {
      $strategyConfig = $configuration['embedding_strategy_configuration'] ?? [];
      $embedding_strategy->init(
        $configuration['embeddings_engine'],
        $configuration['chat_model'],
        $strategyConfig
      );
    }

    // Delete existing documents for these items before re-indexing.
    $this->deleteIndexItems($configuration, $index, array_values(array_map(function ($item) {
      return $item->getId();
    }, $items)));

    $collection_name = $configuration['database_settings']['collection'];
    $collection_id = $this->resolveCollectionId($collection_name);

    // Process all items and collect documents for batch upload.
    foreach ($items as $item) {
      $item_id = $item->getId();

      if ($serverSideEmbeddings) {
        // Server-side mode: use Drupal's content extraction, but skip embedding generation.
        // This uses Drupal's exact field processing (groupFieldData) for consistency.
        $chunks = $this->extractAndChunkContent($item, $index, $configuration, $embedding_strategy);

        foreach ($chunks as $idx => $chunkText) {
          $metadata = array_merge($itemBase['metadata'], [
            'drupal_long_id' => $item_id . '_chunk_' . $idx,
            'drupal_entity_id' => $item_id,
          ]);

          // No vector - Lambda will generate embeddings with parallel processing.
          $documentsToUpload[] = [
            'content' => $chunkText,
            'metadata' => $metadata,
          ];
        }

        $successfulItemIds[] = $item_id;
      }
      else {
        // Standard mode: use Drupal's embedding strategy (sequential, slow).
        try {
          $embeddings = $embedding_strategy->getEmbedding(
            $configuration['embeddings_engine'],
            $configuration['chat_model'],
            $configuration['embedding_strategy_configuration'],
            $item->getFields(),
            $item,
            $index,
          );
        }
        catch (AiUnsafePromptException $e) {
          $this->getLogger('ai_provider_quant_cloud')->warning('Skipping item @id due to unsafe prompt: @message', [
            '@id' => $item_id,
            '@message' => $e->getMessage(),
          ]);
          continue;
        }

        $chunk_count = count($embeddings);
        $this->getLogger('ai_provider_quant_cloud')->debug('Item @id generated @chunks chunks', [
          '@id' => $item_id,
          '@chunks' => $chunk_count,
        ]);

        foreach ($embeddings as $embedding) {
          $this->validateRetrievedEmbedding($embedding);

          $embedding = array_merge_recursive($embedding, $itemBase);

          // Build metadata.
          $metadata = [
            'drupal_long_id' => $embedding['id'],
            'drupal_entity_id' => $item_id,
          ];
          foreach ($embedding['metadata'] as $key => $value) {
            $metadata[$key] = $value;
          }

          // Collect document for batch upload.
          // Use actual content text for storage (not ID), fallback to ID if content not available.
          $contentText = $embedding['metadata']['content'] ?? $embedding['content'] ?? $embedding['id'];

          // Remove content from metadata since it's stored in the content column.
          unset($metadata['content']);

          $documentsToUpload[] = [
            'content' => $contentText,
            'metadata' => $metadata,
            'vector' => $embedding['values'],
          ];
        }

        $successfulItemIds[] = $item_id;
      }
    }

    // Batch upload all documents in a single API call.
    if (!empty($documentsToUpload)) {
      try {
        $hasVectors = isset($documentsToUpload[0]['vector']);
        $this->getLogger('ai_provider_quant_cloud')->info('Sending batch upload: @items items, @docs documents (precomputed_vectors: @pv)', [
          '@items' => count($items),
          '@docs' => count($documentsToUpload),
          '@pv' => $hasVectors ? 'yes' : 'no',
        ]);

        $response = $this->vdbClient->uploadDocuments($collection_id, $documentsToUpload);

        $this->getLogger('ai_provider_quant_cloud')->info('Batch upload complete: @docs documents to collection @collection', [
          '@docs' => count($documentsToUpload),
          '@collection' => $collection_name,
        ]);
      }
      catch (\Exception $e) {
        $this->getLogger('ai_provider_quant_cloud')->error('Batch upload failed: @message', [
          '@message' => $e->getMessage(),
        ]);
        // If batch fails, return empty - no items were successfully indexed.
        return [];
      }
    }

    return $successfulItemIds;
  }

  /**
   * Extract text content from a Search API item and chunk it.
   *
   * Uses Drupal's EmbeddingStrategy for content extraction (groupFieldData)
   * to ensure identical content processing. Only the chunking is done locally,
   * and embeddings are generated server-side in parallel.
   *
   * @param \Drupal\search_api\Item\ItemInterface $item
   *   The Search API item.
   * @param \Drupal\search_api\IndexInterface $index
   *   The Search API index.
   * @param array $configuration
   *   The configuration array.
   * @param \Drupal\ai_search\EmbeddingStrategyInterface $embedding_strategy
   *   The embedding strategy plugin (used for content extraction only).
   *
   * @return array
   *   Array of text chunks formatted identically to Drupal AI Search.
   */
  protected function extractAndChunkContent($item, IndexInterface $index, array $configuration, EmbeddingStrategyInterface $embedding_strategy): array {
    // Use Drupal's exact field extraction via groupFieldData().
    // This ensures identical content processing (entity references, HTML to
    // markdown, etc.) regardless of whether embeddings are generated locally
    // or server-side.
    [$title, $contextualContent, $mainContent] = $embedding_strategy->groupFieldData(
      $item->getFields(),
      $index
    );

    if (empty($mainContent) && empty($title) && empty($contextualContent)) {
      return [];
    }

    // For server-side embeddings, send content as a single chunk.
    // The Lambda handles chunking with proper token-based logic,
    // which is more accurate than character-based chunking.
    return [$this->prepareChunkText($title, $mainContent, $contextualContent)];
  }

  /**
   * Format a chunk with title and contextual content.
   *
   * Replicates EmbeddingBase::prepareChunkText() format exactly.
   *
   * @param string $title
   *   The title.
   * @param string $mainChunk
   *   The main content chunk.
   * @param string $contextualChunk
   *   The contextual content.
   *
   * @return string
   *   Formatted chunk text.
   */
  protected function prepareChunkText(string $title, string $mainChunk, string $contextualChunk): string {
    $parts = [];
    if (!empty($title)) {
      $parts[] = '# ' . strtoupper($title);
    }
    $parts[] = $mainChunk;
    if (!empty($contextualChunk)) {
      $parts[] = $contextualChunk;
    }
    return implode("\n\n", $parts);
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
    // Use actual content if available, not just the ID.
    $contentText = $data['content'] ?? $data['drupal_long_id'] ?? 'indexed_content';

    $document = [
      'content' => $contentText,
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
   *
   * Override to delete by drupal_entity_id metadata instead of VDB document IDs.
   * This is more reliable than maintaining ID mappings in state.
   */
  public function deleteItems(array $configuration, array $item_ids): void {
    if (empty($item_ids)) {
      return;
    }

    $collection_name = $configuration['database_settings']['collection'];

    try {
      $collection_id = $this->resolveCollectionId($collection_name);

      // Delete documents by drupal_entity_id metadata field.
      // This matches all chunks for the given entity IDs.
      $response = $this->vdbClient->deleteDocuments(
        $collection_id,
        FALSE,
        [],
        'drupal_entity_id',
        $item_ids
      );

      $deleted_count = $response['deletedCount'] ?? 0;
      $this->getLogger('ai_provider_quant_cloud')->info('Deleted @deleted documents for @entities entities from collection @collection', [
        '@deleted' => $deleted_count,
        '@entities' => count($item_ids),
        '@collection' => $collection_name,
      ]);
    }
    catch (\Exception $e) {
      $this->getLogger('ai_provider_quant_cloud')->error('Failed to delete documents: @message', [
        '@message' => $e->getMessage(),
      ]);
      // Don't throw - deletion failure shouldn't break indexing.
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
    // This method is bypassed by our deleteItems override.
    // Kept for interface compliance.
    if (empty($ids)) {
      return;
    }

    try {
      $collection_id = $this->resolveCollectionId($collection_name);

      // Delete documents by drupal_long_id metadata field.
      $response = $this->vdbClient->deleteDocuments(
        $collection_id,
        FALSE,
        [],
        'drupal_long_id',
        $ids
      );

      $deleted_count = $response['deletedCount'] ?? 0;
      $this->getLogger('ai_provider_quant_cloud')->info('Deleted @deleted of @requested documents from collection @collection', [
        '@deleted' => $deleted_count,
        '@requested' => count($ids),
        '@collection' => $collection_name,
      ]);
    }
    catch (\Exception $e) {
      $this->getLogger('ai_provider_quant_cloud')->error('Failed to delete documents: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   *
   * Override to use purgeAll instead of dropping/recreating the collection.
   */
  public function deleteAllIndexItems(array $configuration, IndexInterface $index, $datasource_id = NULL): void {
    $collection_name = $configuration['database_settings']['collection'];

    try {
      $collection_id = $this->resolveCollectionId($collection_name);

      // Use purgeAll to delete all documents while preserving the collection.
      $response = $this->vdbClient->deleteDocuments($collection_id, TRUE);

      $deleted_count = $response['deletedCount'] ?? 0;
      $this->getLogger('ai_provider_quant_cloud')->info('Purged all @count documents from collection @collection', [
        '@count' => $deleted_count,
        '@collection' => $collection_name,
      ]);

      // Clear the ID mapping state.
      $state_key = "ai_provider_quant_cloud.vdb_mapping.{$collection_name}";
      \Drupal::state()->delete($state_key);
    }
    catch (\Exception $e) {
      $this->getLogger('ai_provider_quant_cloud')->error('Failed to purge collection: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
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
        TRUE  // Include metadata
      );

      // Map API response to expected format.
      // Drupal's SearchApiAiSearchBackend expects:
      // - 'distance' for score (used by setScore(), skipped by extractMetadata())
      // - 'drupal_entity_id' for entity lookup
      // - 'id' for the full chunk ID (skipped by extractMetadata())
      // - 'content' is added to extra data for display
      $results = [];
      foreach ($response['results'] ?? [] as $result) {
        $metadata = $result['metadata'] ?? [];
        $results[] = [
          'id' => $metadata['drupal_long_id'] ?? $result['documentId'],
          'drupal_entity_id' => $metadata['drupal_entity_id'] ?? NULL,
          'distance' => $result['score'] ?? 0.0,  // Backend expects 'distance', not 'score'
          'content' => $result['content'] ?? '',
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

    // Fix the collection description - it CAN be changed.
    $form['collection']['#description'] = $this->t('The collection to use. This will be created automatically if it does not exist.');

    // Hide database_name - not relevant for Quant Cloud (uses org from config).
    $form['database_name']['#type'] = 'hidden';
    $form['database_name']['#value'] = 'quant_cloud';

    // Hide metric selector - Quant Cloud uses cosine similarity only.
    if (isset($form['metric'])) {
      $form['metric']['#type'] = 'hidden';
      $form['metric']['#value'] = VdbSimilarityMetrics::CosineSimilarity->value;
    }

    // Server-side embeddings option - significantly faster indexing.
    $form['server_side_embeddings'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Server-side embeddings (recommended)'),
      '#description' => $this->t('Generate embeddings on Quant Cloud servers instead of Drupal. This is <strong>significantly faster</strong> because Quant Cloud processes embeddings in parallel, while Drupal processes them sequentially. Enable this for faster indexing.'),
      '#default_value' => $configuration['database_settings']['server_side_embeddings'] ?? TRUE,
      '#weight' => -10,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function viewIndexSettings(array $database_settings): array {
    return [];
  }

}
