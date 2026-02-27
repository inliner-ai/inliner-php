<?php

namespace Inliner;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class InlinerException extends RuntimeException {}

class InlinerClient
{
    private string $apiKey;
    private string $apiUrl;
    private string $imageUrl;
    private Client $client;

    public function __construct(
        string $apiKey,
        string $apiUrl = "https://api.inliner.ai",
        string $imageUrl = "https://img.inliner.ai",
        ?Client $client = null
    ) {
        $this->apiKey = $apiKey;
        $this->apiUrl = rtrim($apiUrl, "/");
        $this->imageUrl = rtrim($imageUrl, "/");
        $this->client = $client ?? new Client([
            'timeout' => 60.0,
        ]);
    }

    private function apiFetch(string $method, string $path, array $options = []): array
    {
        $url = $this->apiUrl . "/" . ltrim($path, "/");
        
        $defaultOptions = [
            'headers' => [
                'Authorization' => "Bearer " . $this->apiKey,
                'Accept' => 'application/json',
            ],
        ];

        try {
            $response = $this->client->request($method, $url, array_merge_recursive($defaultOptions, $options));
            $body = (string) $response->getBody();
            return json_decode($body, true) ?? [];
        } catch (GuzzleException $e) {
            $message = $e->getMessage();
            if ($e->hasResponse()) {
                $errorBody = json_decode((string) $e->getResponse()->getBody(), true);
                $message = $errorBody['message'] ?? $message;
            }
            throw new InlinerException("Inliner API error: " . $message, $e->getCode());
        }
    }

    // --- Generation & Editing ---

    /**
     * Generate an image from a text prompt.
     */
    public function generateImage(
        string $project,
        string $prompt,
        ?int $width = null,
        ?int $height = null,
        string $format = "png",
        bool $smartUrl = true
    ): array {
        $slug = $this->slugify($prompt);

        if ($smartUrl) {
            try {
                $rec = $this->apiFetch("POST", "url/recommend", [
                    'json' => [
                        'prompt' => $prompt,
                        'project' => $project,
                        'width' => $width,
                        'height' => $height,
                        'extension' => $format,
                    ]
                ]);
                $slug = $rec['recommended_slug'] ?? $slug;
            } catch (\Exception $e) {
                // Fallback to manual slug
            }
        }

        $result = $this->apiFetch("POST", "content/generate", [
            'json' => [
                'prompt' => $prompt,
                'project' => $project,
                'slug' => $slug,
                'width' => $width,
                'height' => $height,
                'extension' => $format,
            ]
        ]);

        $contentPath = ltrim($result['prompt'] ?? "", "/") ?: "$project/$slug.$format";

        if (isset($result['mediaAsset']['data'])) {
            return $this->wrapResult($result['mediaAsset']['data'], $contentPath);
        }

        return $this->pollImage($contentPath, "Generating");
    }

    /**
     * Edit an existing image or a local file.
     */
    public function editImage(
        mixed $source,
        string $instruction,
        ?string $project = null,
        ?int $width = null,
        ?int $height = null,
        string $format = "png"
    ): array {
        $editSlug = $this->slugify($instruction);
        $dimsSuffix = ($width && $height) ? "_{$width}x{$height}" : "";

        if (is_string($source) && str_starts_with($source, "http")) {
            // URL Chaining
            $urlParts = parse_url($source);
            $basePath = ltrim($urlParts['path'] ?? '', "/");
            $contentPath = "$basePath/$editSlug$dimsSuffix.$format";
            return $this->pollImage($contentPath, "Editing");
        } else {
            // Upload then edit
            if (!$project) {
                throw new InlinerException("Project namespace is required when editing local files");
            }

            $uploadName = $this->slugify("edit-source-" . time());
            $upload = $this->uploadImage($source, "$uploadName.png", $project, $uploadName);
            
            $uploadedPath = ltrim($upload['content']['prompt'], "/");
            $contentPath = "$uploadedPath/$editSlug$dimsSuffix.$format";
            return $this->pollImage($contentPath, "Editing");
        }
    }

    public function pollImage(string $contentPath, string $label, int $maxSeconds = 180): array
    {
        $jsonPath = "content/request-json/" . $contentPath;
        $imgUrl = $this->imageUrl . "/" . $contentPath;
        $maxAttempts = (int) ($maxSeconds / 3);

        for ($i = 0; $i < $maxAttempts; $i++) {
            try {
                $data = $this->apiFetch("GET", $jsonPath);
                
                if (isset($data['mediaAsset']['data'])) {
                    return $this->wrapResult($data['mediaAsset']['data'], $contentPath);
                }

                // Fallback: Check CDN directly
                $response = $this->client->request('GET', $imgUrl);
                if ($response->getStatusCode() === 200) {
                    return [
                        'data' => (string) $response->getBody(),
                        'url' => $imgUrl,
                        'content_path' => $contentPath
                    ];
                }
            } catch (\Exception $e) {
                // Ignore 202 and transient errors during polling
            }

            sleep(3);
        }

        throw new InlinerException("$label timed out after {$maxSeconds}s");
    }

    // --- Asset Management ---

    public function uploadImage(
        mixed $file,
        string $filename,
        string $project,
        ?string $slug = null,
        ?string $title = null,
        ?string $description = null,
        ?array $tags = null,
        ?string $collectionId = null
    ): array {
        $multipart = [
            [
                'name'     => 'file',
                'contents' => is_resource($file) ? $file : (is_string($file) && !file_exists($file) ? $file : fopen($file, 'r')),
                'filename' => $filename
            ],
            ['name' => 'project', 'contents' => $project],
            ['name' => 'prompt', 'contents' => $slug ?: $this->slugify(pathinfo($filename, PATHINFO_FILENAME))]
        ];

        if ($title) $multipart[] = ['name' => 'title', 'contents' => $title];
        if ($description) $multipart[] = ['name' => 'description', 'contents' => $description];
        if ($collectionId) $multipart[] = ['name' => 'collectionId', 'contents' => $collectionId];
        
        $query = [];
        if ($tags) {
            foreach ($tags as $tag) {
                $query['tags[]'] = $tag;
            }
        }

        return $this->apiFetch("POST", "content/upload", [
            'multipart' => $multipart,
            'query' => $query
        ]);
    }

    public function listImages(array $options = []): array
    {
        return $this->apiFetch("GET", "content/images", [
            'query' => $options
        ]);
    }

    public function search(string $expression, array $options = []): array
    {
        return $this->apiFetch("GET", "content/search", [
            'query' => array_merge(['expression' => $expression], $options)
        ]);
    }

    public function deleteImages(array $contentIds): array
    {
        return $this->apiFetch("POST", "content/delete", [
            'json' => ['contentIds' => $contentIds]
        ]);
    }

    public function renameImage(string $contentId, string $newUrl): array
    {
        return $this->apiFetch("POST", "content/rename/$contentId", [
            'json' => ['newUrl' => $newUrl]
        ]);
    }

    // --- Tagging ---

    public function getAllTags(): array
    {
        return $this->apiFetch("GET", "content/tags");
    }

    public function addTags(array $contentIds, array $tags): array
    {
        return $this->apiFetch("POST", "content/tags", [
            'json' => ['contentIds' => $contentIds, 'tags' => $tags]
        ]);
    }

    public function removeTags(array $contentIds, array $tags): array
    {
        return $this->apiFetch("POST", "content/tags/remove", [
            'json' => ['contentIds' => $contentIds, 'tags' => $tags]
        ]);
    }

    public function replaceTags(array $contentIds, array $tags): array
    {
        return $this->apiFetch("POST", "content/tags/replace", [
            'json' => ['contentIds' => $contentIds, 'tags' => $tags]
        ]);
    }

    // --- Projects ---

    public function listProjects(): array
    {
        return $this->apiFetch("GET", "account/projects");
    }

    public function createProject(array $projectData): array
    {
        return $this->apiFetch("POST", "account/projects", [
            'json' => $projectData
        ]);
    }

    public function getProjectDetails(string $projectId): array
    {
        return $this->apiFetch("GET", "account/projects/$projectId");
    }

    // --- Helpers ---

    public function buildImageUrl(string $project, string $description, int $width, int $height, string $format = "png"): string
    {
        $slug = $this->slugify($description);
        return "{$this->imageUrl}/$project/{$slug}_{$width}x{$height}.$format";
    }

    public function slugify(string $text): string
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9-]+/', '-', $text);
        $text = preg_replace('/-+/', '-', $text);
        return trim($text, '-');
    }

    private function wrapResult(string $base64Data, string $contentPath): array
    {
        if (str_contains($base64Data, ",")) {
            $base64Data = explode(",", $base64Data)[1];
        }

        return [
            'data' => base64_decode($base64Data),
            'url' => $this->imageUrl . "/" . $contentPath,
            'content_path' => $contentPath
        ];
    }
}
