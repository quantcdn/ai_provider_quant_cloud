# Quant Cloud AI Provider

Drupal AI provider module for Quant Cloud platform (QuantCDN & QuantGov Cloud).

## Overview

This module provides a **standard Drupal AI provider** that connects to Quant Cloud AI services, giving you access to AWS Bedrock models (Claude, Nova, Titan) through the Quant Cloud platform.

### What is a Provider Module?

A provider module is a **connector** between Drupal and external AI services. It implements standard Drupal AI interfaces so that other modules can use AI capabilities without worrying about the underlying API.

**This module provides:**
- ✅ Chat completions (multi-turn conversations)
- ✅ Embeddings generation (for semantic search)
- ✅ Dynamic model discovery from Quant Cloud API
- ✅ OAuth2 authentication
- ✅ Automatic request routing through Quant Cloud Dashboard

**This module does NOT include:**
- ❌ User interface components (chatbots, forms)
- ❌ Content generation features
- ❌ Business logic

For features, install consumer modules such as:
- `ai_automator` - Automated content generation
- `ai_content` - AI-powered content tools
- Or build your own custom modules using the Drupal AI API

## Requirements

- Drupal 10.3+ or Drupal 11+
- [AI module](https://www.drupal.org/project/ai)
- [Key module](https://www.drupal.org/project/key)
- Active Quant Cloud account (QuantCDN or QuantGov Cloud)

## Installation

```bash
composer require drupal/ai drupal/key
drush en -y ai key

# Enable this module (when installed in custom/modules)
drush en -y ai_provider_quant_cloud
```

## Configuration

### 1. Create an Access Token Key

1. Go to **Configuration > System > Keys**
2. Click "Add key"
3. Fill in:
   - **Key name**: "Quant Cloud Access Token"
   - **Key type**: "Authentication"
   - **Key provider**: "Configuration"
   - **Key value**: Your OAuth token from the Quant Cloud dashboard

### 2. Configure the Provider

1. Go to **Configuration > AI > Quant Cloud AI**
2. Select your platform (QuantCDN or QuantGov Cloud)
3. Choose authentication method:
   - **OAuth** (recommended): Click "Connect to Quant Cloud" to authorize
   - **Manual Token**: Select the access token key you created
4. Select your organization from the dropdown
5. Save configuration

### 3. Verify Connection

After saving, the module will:
- Validate your access token
- Fetch available AI models from Quant Cloud
- Cache model information for 1 hour
- Display connection status on the configuration page

## Usage

### Basic Chat Example

```php
<?php

// Get the AI provider service
$provider = \Drupal::service('ai.provider')->createInstance('quant_cloud');

// Simple string input
$response = $provider->chat(
  'What is Drupal?',
  'amazon.nova-lite-v1:0'
);

// Get the response text
$text = $response->getNormalized()->getText();

// Display
\Drupal::messenger()->addMessage($text);
```

### Multi-Turn Chat Example

```php
<?php

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;

// Create conversation
$messages = [
  new ChatMessage('user', 'What is Drupal?'),
  new ChatMessage('assistant', 'Drupal is a powerful open-source CMS...'),
  new ChatMessage('user', 'What are its main features?'),
];

$input = new ChatInput($messages);
$response = $provider->chat($input, 'anthropic.claude-3-5-sonnet-20241022-v2:0');

$text = $response->getNormalized()->getText();
```

### Embeddings Example

```php
<?php

use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;

// Single text
$response = $provider->embeddings(
  'This is a test document',
  'amazon.titan-embed-text-v2:0'
);

// Multiple texts
$texts = [
  'Government services',
  'Tax information',
  'Healthcare resources',
];

$input = new EmbeddingsInput($texts);
$response = $provider->embeddings($input, 'amazon.titan-embed-text-v2:0');

// Get embedding vectors
$vectors = $response->getEmbeddings();
// Returns: Array of 1024-dimensional vectors
```

## Available Models

### Chat & Completion Models
- `amazon.nova-lite-v1:0` - Fast and affordable
- `amazon.nova-micro-v1:0` - Ultra-fast
- `amazon.nova-pro-v1:0` - Advanced capabilities
- `anthropic.claude-3-5-sonnet-20241022-v2:0` - Latest Claude
- `anthropic.claude-3-5-sonnet-20240620-v1:0` - Claude 3.5
- `anthropic.claude-3-sonnet-20240229-v1:0` - Claude 3
- `anthropic.claude-3-haiku-20240307-v1:0` - Claude Haiku

### Embeddings Models
- `amazon.titan-embed-text-v2:0` - Text embeddings (256/512/1024 dimensions)

## Architecture

```
┌─────────────────────────────────────┐
│  Your Feature Modules               │
│  (content generator, chatbot, etc)  │
└──────────────┬──────────────────────┘
               │ Uses AI API
               ▼
┌─────────────────────────────────────┐
│  Drupal AI Module                   │
│  (Plugin Manager, Interfaces)       │
└──────────────┬──────────────────────┘
               │ Implements
               ▼
┌─────────────────────────────────────┐
│  ai_provider_quant_cloud (THIS)     │
│  - QuantCloudProvider               │
│  - QuantCloudClient                 │
│  - OAuth2 Authentication            │
└──────────────┬──────────────────────┘
               │ HTTPS (Dashboard API)
               ▼
┌─────────────────────────────────────┐
│  Quant Cloud Platform               │
│  - QuantCDN / QuantGov              │
│  - AWS Bedrock Models               │
└─────────────────────────────────────┘
```

## Troubleshooting

### Provider not appearing

```bash
drush cr
drush ev "print_r(\Drupal::service('plugin.manager.ai_provider')->getDefinitions());"
```

### Authentication errors

- Check that your access token is valid in the Quant Cloud dashboard
- Verify organization ID is correct
- Try reconnecting with OAuth
- Check Drupal logs: `drush watchdog:show --type=ai_provider_quant_cloud`

### Module configuration not saving

- Clear cache: `drush cr`
- Check file permissions
- Verify Key module is enabled

## Development

### Running Tests

```bash
# PHPUnit
vendor/bin/phpunit modules/custom/ai_provider_quant_cloud/tests/

# PHPStan
vendor/bin/phpstan analyze modules/custom/ai_provider_quant_cloud/
```

### Coding Standards

```bash
# Check standards
vendor/bin/phpcs --standard=Drupal modules/custom/ai_provider_quant_cloud/

# Fix automatically
vendor/bin/phpcbf --standard=Drupal modules/custom/ai_provider_quant_cloud/
```

## Resources

- [Quant Cloud Platform](https://quantcdn.io)
- [QuantGov Cloud](https://quantgov.cloud)
- [Drupal AI Module](https://www.drupal.org/project/ai)
- [Quant Cloud Documentation](https://docs.quantcdn.io)

## Support

For issues and feature requests:
- [Issue Queue](https://www.drupal.org/project/issues/ai_provider_quant_cloud)
- [Quant Cloud Support](https://support.quantcdn.io)

## License

GPL-2.0-or-later

## Maintainers

- Quant Cloud - https://quantcdn.io

