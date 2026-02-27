# inliner-php

The official PHP client for [Inliner.ai](https://inliner.ai). Generate, edit, and manage AI images with a clean, object-oriented API.

## Installation

Install the package via [Composer](https://getcomposer.org/):

```bash
composer require inliner/inliner-php
```

## Quick Start

```php
<?php

require 'vendor/autoload.php';

use Inliner\InlinerClient;

// Initialize with your API key
$client = new InlinerClient("YOUR_INLINER_API_KEY");

// 1. Generate a new image (polls until complete)
$result = $client->generateImage(
    project: "web",
    prompt: "a futuristic neon lizard catching a fly",
    width: 800,
    height: 600
);

echo "Generated URL: " . $result['url'];
// $result['data'] contains the raw image bytes

// 2. Add tags to existing images
$client->addTags(["uuid-abc-123"], ["nature", "wildlife"]);

// 3. Search with multi-tag filtering (AND logic)
$search = $client->search(
    expression: "tags:nature AND tags:wildlife",
    options: ['max_results' => 10]
);

foreach ($search['items'] as $item) {
    echo "Title: {$item['title']}, URL: {$item['url']}
";
}
```

## Core Features

- **Integrated Hosting**: Every generation is automatically hosted on a global CDN.
- **AI Asset Management**: Automated tagging, descriptions, and semantic search out of the box.
- **URL Transformations**: Resize and edit images programmatically via natural language.
- **PSR-4 Compliant**: Follows modern PHP standards for easy integration.

## Requirements
- PHP 8.1+
- `GuzzleHttp`

## License
MIT
