# Changelog

All notable changes to the Quant Cloud AI Provider module will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Initial release of Quant Cloud AI Provider for Drupal
- Chat interface implementation with support for multi-turn conversations
- Embeddings generation for semantic search and vector operations
- OAuth2 authentication flow for secure API access
- Manual token authentication for advanced use cases
- Dynamic model discovery from Quant Cloud API with caching
- Dynamic token limits fetched from API (contextWindow, maxOutputTokens)
- Automatic organization selection via Dashboard API
- Support for both QuantCDN and QuantGov Cloud platforms
- Dashboard API integration for multi-tenant AI services
- Comprehensive error handling and logging
- Form-based configuration UI with real-time validation
- Per-request streaming support via tags
- Compatible with Drupal 10.3+ and Drupal 11

### VectorDB Support
- **New submodule**: `ai_provider_quant_cloud_vdb` for Search API / AI Search integration
- `QuantCloudVectorDbClient` for direct VectorDB API access (no Search API required)
- `QuantCloudVdbProvider` plugin implementing `AiVdbProviderInterface`
- Collection management: create, list, delete collections
- Document operations: upload with chunking, delete by ID/URL/pattern
- Search capabilities: text query and pre-computed vector search
- Threshold-based filtering for search results
- Pre-computed vector support for client-side embeddings (Drupal AI Search compatibility)
- Automatic dimension handling (defaults to 1024 for Titan v2)
- Cosine similarity metric (hardcoded for consistency)
- Full Search API Views integration

### Technical Details
- Implements Drupal AI `ChatInterface` and `EmbeddingsInterface`
- VDB submodule implements `AiVdbProviderInterface` and `AiVdbProviderSearchApiInterface`
- All required interface methods implemented (100% compliant)
- Token limits dynamically fetched from models API with graceful fallbacks
- Uses Drupal Key module for secure token storage
- HTTP client integration with GuzzleHttp
- Service-based architecture for modularity
- PSR-3 logging integration
- Cache API integration for performance optimization (1-hour model cache)

## [1.0.0-alpha1] - TBD

### Added
- First public alpha release
- Basic chat and embeddings functionality
- OAuth and manual authentication

### Security
- Secure token storage via Drupal Key module
- API authentication via Bearer tokens
- Organization-level access control

---

## Release Process

1. **Alpha**: Initial testing release with core features
2. **Beta**: Feature-complete with expanded testing
3. **RC**: Release candidate for final review
4. **Stable**: Production-ready release

## Upgrade Path

This module follows Drupal's standard upgrade patterns. Configuration schema changes will be handled via update hooks.

## Support

For issues and feature requests:
- [Issue Queue](https://www.drupal.org/project/issues/ai_provider_quant_cloud)
- [Quant Cloud Support](https://support.quantcdn.io)

