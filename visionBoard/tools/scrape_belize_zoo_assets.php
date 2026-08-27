<?php

declare(strict_types=1);

$baseUrl = 'https://www.belizezoo.org';
$projectRoot = dirname(__DIR__);
$outputRoot = $projectRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'source' . DIRECTORY_SEPARATOR . 'belize-zoo';
$htmlDir = $outputRoot . DIRECTORY_SEPARATOR . 'html';
$imagesDir = $outputRoot . DIRECTORY_SEPARATOR . 'images';
$videosDir = $outputRoot . DIRECTORY_SEPARATOR . 'videos';

foreach ([$outputRoot, $htmlDir, $imagesDir, $videosDir] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        fwrite(STDERR, "Failed to create directory: {$dir}\n");
        exit(1);
    }
}

function fetchText(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Codex Belize Zoo Asset Collector\r\n",
            'timeout' => 30,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException("Failed to fetch {$url}");
    }

    return $body;
}

function fetchBinary(string $url, string $targetPath): void
{
    $data = fetchText($url);
    if (file_put_contents($targetPath, $data) === false) {
        throw new RuntimeException("Failed to write {$targetPath}");
    }
}

function extractLocs(string $xml): array
{
    $doc = new DOMDocument();
    $doc->loadXML($xml);
    $locs = [];

    foreach ($doc->getElementsByTagName('loc') as $loc) {
        $value = trim($loc->textContent);
        if ($value !== '') {
            $locs[] = $value;
        }
    }

    return array_values(array_unique($locs));
}

function normalizeUrl(string $candidate, string $baseUrl): ?string
{
    $candidate = trim($candidate, " \t\n\r\0\x0B\"'");
    if ($candidate === '' || str_starts_with($candidate, 'data:') || str_starts_with($candidate, '#')) {
        return null;
    }

    if (str_starts_with($candidate, '//')) {
        $candidate = 'https:' . $candidate;
    } elseif (str_starts_with($candidate, '/')) {
        $candidate = rtrim($baseUrl, '/') . $candidate;
    }

    if (!preg_match('#^https?://#i', $candidate)) {
        return null;
    }

    $parts = parse_url($candidate);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }

    return sprintf('%s://%s%s', $parts['scheme'], $parts['host'], $parts['path'] ?? '');
}

function isBelizeZooAsset(string $url): bool
{
    $parts = parse_url($url);
    if (!$parts || empty($parts['host']) || empty($parts['path'])) {
        return false;
    }

    if (!preg_match('/(^|\.)belizezoo\.org$/i', $parts['host'])) {
        return false;
    }

    if (!str_contains($parts['path'], '/wp-content/uploads/')) {
        return false;
    }

    return (bool) preg_match('/\.(jpg|jpeg|png|webp|gif|svg|bmp|avif|mp4|webm|mov|m4v|avi)$/i', $parts['path']);
}

function isVideo(string $url): bool
{
    return (bool) preg_match('/\.(mp4|webm|mov|m4v|avi)$/i', parse_url($url, PHP_URL_PATH) ?? '');
}

function slugFromUrl(string $url): string
{
    $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
    if ($path === '') {
        return 'home';
    }

    return preg_replace('/[^a-zA-Z0-9\-_]+/', '-', str_replace('/', '__', $path)) ?: 'page';
}

function addExtractedUrls(string $html, string $baseUrl, array &$mediaUrls, array &$externalVideoRefs): void
{
    $patterns = [
        '/https?:\/\/[^"\'>\s]+/i',
        '/(?<=src=")[^"]+/i',
        '/(?<=href=")[^"]+/i',
        '/(?<=poster=")[^"]+/i',
        '/(?<=data-src=")[^"]+/i',
        '/(?<=data-lazy-src=")[^"]+/i',
        '/(?<=srcset=")[^"]+/i',
        '/(?<=url\()[^)]+/i',
    ];

    foreach ($patterns as $pattern) {
        if (!preg_match_all($pattern, $html, $matches)) {
            continue;
        }

        foreach ($matches[0] as $match) {
            $candidates = [$match];
            if (stripos($pattern, 'srcset') !== false) {
                $candidates = [];
                foreach (explode(',', $match) as $item) {
                    $parts = preg_split('/\s+/', trim($item));
                    if (!empty($parts[0])) {
                        $candidates[] = $parts[0];
                    }
                }
            }

            foreach ($candidates as $candidate) {
                $normalized = normalizeUrl($candidate, $baseUrl);
                if ($normalized === null) {
                    continue;
                }

                if (preg_match('/youtube|youtu\.be|vimeo/i', $normalized)) {
                    $externalVideoRefs[$normalized] = true;
                }

                if (isBelizeZooAsset($normalized)) {
                    $mediaUrls[$normalized] = true;
                }
            }
        }
    }
}

$sitemapIndex = fetchText($baseUrl . '/wp-sitemap.xml');
$sitemapLocs = extractLocs($sitemapIndex);
$allowedSitemaps = array_values(array_filter(
    $sitemapLocs,
    static fn(string $url): bool => (bool) preg_match('/wp-sitemap-posts-(post|page|product|donation)-/i', $url)
));

$pageUrls = [$baseUrl . '/' => true];
foreach ($allowedSitemaps as $sitemapUrl) {
    try {
        foreach (extractLocs(fetchText($sitemapUrl)) as $loc) {
            if (!str_contains($loc, 'wp-sitemap')) {
                $pageUrls[$loc] = true;
            }
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "Skipping sitemap {$sitemapUrl}: {$e->getMessage()}\n");
    }
}

ksort($pageUrls);

$mediaUrls = [];
$externalVideoRefs = [];
$pageSummaries = [];

foreach (array_keys($pageUrls) as $pageUrl) {
    try {
        $html = fetchText($pageUrl);
        $slug = slugFromUrl($pageUrl);
        file_put_contents($htmlDir . DIRECTORY_SEPARATOR . $slug . '.html', $html);

        $before = count($mediaUrls);
        addExtractedUrls($html, $baseUrl, $mediaUrls, $externalVideoRefs);
        $pageSummaries[] = [
            'page' => $pageUrl,
            'media_found' => count($mediaUrls) - $before,
        ];
    } catch (Throwable $e) {
        $pageSummaries[] = [
            'page' => $pageUrl,
            'media_found' => -1,
            'error' => $e->getMessage(),
        ];
    }
}

ksort($mediaUrls);
$downloads = [];

foreach (array_keys($mediaUrls) as $mediaUrl) {
    $path = (string) parse_url($mediaUrl, PHP_URL_PATH);
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $basename = pathinfo($path, PATHINFO_FILENAME);
    $safeBase = preg_replace('/[^a-zA-Z0-9\-_]+/', '-', $basename) ?: 'asset';
    $hash = substr(sha1($mediaUrl), 0, 8);
    $subdir = isVideo($mediaUrl) ? $videosDir : $imagesDir;
    $targetPath = $subdir . DIRECTORY_SEPARATOR . "{$safeBase}_{$hash}.{$ext}";

    try {
        fetchBinary($mediaUrl, $targetPath);
        $downloads[] = [
            'url' => $mediaUrl,
            'file' => $targetPath,
            'type' => isVideo($mediaUrl) ? 'videos' : 'images',
        ];
    } catch (Throwable $e) {
        $downloads[] = [
            'url' => $mediaUrl,
            'error' => $e->getMessage(),
        ];
    }
}

$manifest = [
    'scraped_at' => date(DATE_ATOM),
    'source_site' => $baseUrl,
    'sitemap_count' => count($allowedSitemaps),
    'page_count' => count($pageUrls),
    'media_url_count' => count($mediaUrls),
    'downloaded_count' => count(array_filter($downloads, static fn(array $item): bool => isset($item['file']))),
    'failed_count' => count(array_filter($downloads, static fn(array $item): bool => isset($item['error']))),
    'external_video_refs' => array_keys($externalVideoRefs),
    'pages' => $pageSummaries,
    'downloads' => $downloads,
];

file_put_contents(
    $outputRoot . DIRECTORY_SEPARATOR . 'manifest.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
);

$summary = [
    'root' => $outputRoot,
    'sitemaps' => count($allowedSitemaps),
    'pages' => count($pageUrls),
    'media_urls' => count($mediaUrls),
    'images' => count(glob($imagesDir . DIRECTORY_SEPARATOR . '*') ?: []),
    'videos' => count(glob($videosDir . DIRECTORY_SEPARATOR . '*') ?: []),
    'external_video_refs' => count($externalVideoRefs),
];

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
