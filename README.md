# Quant Cloud AI Provider

Drupal AI provider module for Quant Cloud platform (QuantCDN & QuantGov Cloud).

## Overview

This module provides a **standard Drupal AI provider** that connects to Quant Cloud AI services, giving you access to AWS Bedrock models (Claude, Nova, Titan) through the Quant Cloud platform.

### What is a Provider Module?

A provider module is a **connector** between Drupal and external AI services. It implements standard Drupal AI interfaces so that other modules can use AI capabilities without worrying about the underlying API.

**This module provides:**
- ✅ Chat completions (multi-turn conversations)
- ✅ Streaming responses (real-time chat)
- ✅ Function calling / Tool execution (with auto-execute support)
- ✅ Embeddings generation (for semantic search)
- ✅ Text-to-image generation (AWS Bedrock Nova Canvas)
- ✅ Image-to-image transformations (variations, inpainting, outpainting, background removal)
- ✅ Structured output (JSON Schema validation)
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

### 4. Advanced Configuration (Optional)

Configure additional options in the "Advanced Settings" section:

- **Enable Request/Response Logging**: Turn on detailed logging for debugging
- **Streaming Timeout**: Adjust timeout for streaming responses (default: 60s)
- **Image Generation Options**:
  - Default resolution (512x512 to 2048x2048)
  - Quality preset (standard/premium)
  - Visual style (photorealistic, digital-art, illustration, etc.)
  - Negative prompts for unwanted elements
- **Model Parameters**:
  - Temperature (0.0 - 1.0)
  - Max tokens (1 - 4096)
  - Top P sampling

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

### Streaming Chat Example

```php
<?php

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;

// Create chat input
$messages = [new ChatMessage('user', 'Tell me a story about Drupal')];
$input = new ChatInput($messages);

// Stream the response
$stream = $provider->streamedChat($input, 'anthropic.claude-3-5-sonnet-20241022-v2:0');

// Iterate through chunks as they arrive
foreach ($stream as $message) {
  echo $message->getText(); // Print each chunk in real-time
}
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

### Text-to-Image Example

```php
<?php

use Drupal\ai\OperationType\TextToImage\TextToImageInput;

// Generate an image
$input = new TextToImageInput('A beautiful sunset over mountains');
$response = $provider->textToImage($input, 'amazon.nova-canvas-v1:0');

// Get generated images
$images = $response->getImages();
foreach ($images as $image) {
  $file = $image->getFile(); // Drupal file entity
  // Use $file->getFileUri() to get the file path
}
```

### Image-to-Image Example

```php
<?php

use Drupal\ai\OperationType\ImageToImage\ImageToImageInput;
use Drupal\file_mdm\FileMetadataInterface;

// Load source image
$file = \Drupal::entityTypeManager()
  ->getStorage('file')
  ->load($fid);

// Create image transformation input
$input = new ImageToImageInput(
  $file,
  'Make this photo look like a watercolor painting',
  'image_variation' // or 'inpainting', 'outpainting', 'background_removal'
);

$response = $provider->imageToImage($input, 'amazon.nova-canvas-v1:0');

// Get transformed images
$images = $response->getImages();
```

### Function Calling Example

```php
<?php

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ToolsInput;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput;

// Define a custom function/tool
$get_weather = new ToolsFunctionInput(
  'get_weather',
  'Get current weather for a location',
  [
    'type' => 'object',
    'properties' => [
      'location' => [
        'type' => 'string',
        'description' => 'City name',
      ],
      'unit' => [
        'type' => 'string',
        'enum' => ['celsius', 'fahrenheit'],
      ],
    ],
    'required' => ['location'],
  ]
);

$tools = new ToolsInput([$get_weather]);

// Ask a question that requires the tool
$messages = [new ChatMessage('user', 'What is the weather like in Sydney?')];
$input = new ChatInput($messages);
$input->setChatTools($tools);

$response = $provider->chat($input, 'anthropic.claude-3-5-sonnet-20241022-v2:0');

// Check if AI wants to call a function
if ($response->getNormalized()->getTools()) {
  foreach ($response->getNormalized()->getTools() as $tool_call) {
    $function_name = $tool_call->getName();
    $arguments = $tool_call->getInput();
    
    // Execute your function
    if ($function_name === 'get_weather') {
      $weather_data = my_weather_api_call($arguments['location']);
      
      // Send result back to AI
      $messages[] = new ChatMessage('assistant', '', $response->getNormalized()->getTools());
      $messages[] = new ChatMessage('tool_result', json_encode($weather_data), [], ['tool_call_id' => $tool_call->getId()]);
      
      $input = new ChatInput($messages);
      $input->setChatTools($tools);
      $final_response = $provider->chat($input, 'anthropic.claude-3-5-sonnet-20241022-v2:0');
      
      echo $final_response->getNormalized()->getText();
      // "The weather in Sydney is currently 22°C and sunny."
    }
  }
}
```

### Structured Output Example

```php
<?php

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;

// Define JSON Schema
$schema = [
  'type' => 'object',
  'properties' => [
    'name' => ['type' => 'string'],
    'age' => ['type' => 'integer'],
    'email' => ['type' => 'string', 'format' => 'email'],
  ],
  'required' => ['name', 'age'],
];

// Request structured output
$messages = [new ChatMessage('user', 'Extract: John Doe, 35 years old, john@example.com')];
$input = new ChatInput($messages);

$response = $provider->chat(
  $input,
  'amazon.nova-pro-v1:0',
  ['json_schema' => $schema]
);

// Parse JSON response
$data = json_decode($response->getNormalized()->getText(), TRUE);
// Returns: ['name' => 'John Doe', 'age' => 35, 'email' => 'john@example.com']
```

## Available Models

Models are dynamically fetched from the Quant Cloud API. Here are the commonly available models:

### Chat & Completion Models
- `amazon.nova-lite-v1:0` - Fast and affordable, great for most tasks
- `amazon.nova-micro-v1:0` - Ultra-fast for simple queries
- `amazon.nova-pro-v1:0` - Advanced capabilities, vision support
- `anthropic.claude-3-5-sonnet-20241022-v2:0` - Latest Claude (recommended)
- `anthropic.claude-3-5-sonnet-20240620-v1:0` - Claude 3.5
- `anthropic.claude-3-sonnet-20240229-v1:0` - Claude 3
- `anthropic.claude-3-haiku-20240307-v1:0` - Claude Haiku (fast)

### Embeddings Models
- `amazon.titan-embed-text-v2:0` - Text embeddings (256/512/1024 dimensions)
- `cohere.embed-english-v3` - English text embeddings
- `cohere.embed-multilingual-v3` - Multilingual embeddings

### Image Generation Models
- `amazon.nova-canvas-v1:0` - Text-to-image, image-to-image, inpainting, outpainting, background removal
- `amazon.titan-image-generator-v2:0` - Alternative image generation model

**Note:** Available models and features may vary by region and Quant Cloud plan. The module automatically fetches and displays available models for your account.

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

### Image generation issues

**"Image too large" errors:**
- The module automatically resizes images to meet Nova Canvas limits (4MP max, 2048px sides)
- If you see this error, ensure the Sharp PHP extension is installed: `composer require drupal/image_effects`

**"No image models available":**
- Verify your Quant Cloud plan includes Bedrock image generation
- Check that `image_generation` feature is enabled in your organization
- Clear model cache: Go to Configuration > AI > Quant Cloud AI and click "Clear Model Cache"

**Image quality issues:**
- Try using `premium` quality in configuration for better results
- Adjust the `similarity_strength` parameter (0.2-1.0) for image variations
- Use more detailed prompts for better control

### Streaming not working

- Ensure your PHP version supports Server-Sent Events (SSE)
- Check that your web server doesn't buffer responses (disable mod_deflate compression for SSE endpoints)
- Increase `streaming_timeout` in advanced settings if timeouts occur
- Enable logging to see streaming debug information

### Module configuration not saving

- Clear cache: `drush cr`
- Check file permissions
- Verify Key module is enabled

### Performance optimization

- **Model caching**: Models are cached for 1 hour - adjust in code if needed
- **Logging**: Disable request/response logging in production
- **Streaming**: Use streaming for long responses to improve perceived performance
- **Embeddings batch**: Process embeddings in batches for better throughput

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

