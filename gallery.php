<?php

require_once __DIR__ . '/request_guard.php';
guardRequest(['GET', 'HEAD']);
applySecurityHeaders();

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/media_helpers.php';
require_once __DIR__ . '/db_helpers.php';
loadEnvFile('/var/www/wedding-upload/.env');

use Aws\S3\S3Client;

// --- Optimized Client Initialization ---
$rawEndpoint = getenv('HETZNER_S3_ENDPOINT') ?: '';
$endpoint = trim($rawEndpoint);
if (!preg_match('#^https?://#i', $endpoint)) {
  $endpoint = 'https://' . $endpoint;
}

$s3 = new S3Client([
  'version' => 'latest',
  'region' => getenv('HETZNER_S3_REGION') ?: 'us-east-1',
  'endpoint' => $endpoint,
  'use_path_style_endpoint' => true,
  'credentials' => [
    'key' => getenv('HETZNER_S3_ACCESS_KEY_ID'),
    'secret' => getenv('HETZNER_S3_SECRET_ACCESS_KEY'),
  ],
]);

$bucket = getenv('HETZNER_S3_BUCKET') ?: '';
$pageToken = $_GET['pageToken'] ?? '0';
$pageSize = 24;
$offset = max(0, (int) $pageToken);

$db = getReadDb(__DIR__ . '/media.sqlite');
if (!$db) {
  header('X-Next-Page-Token: ');
  exit;
}

$db->exec('CREATE INDEX IF NOT EXISTS idx_created_at ON uploads(created_at DESC);');

$stmt = $db->prepare('
  SELECT local_key, file_name, mime_type, kind, object_key, preview_data_uri, thumb_object_key, created_at
  FROM uploads
  ORDER BY datetime(created_at) DESC
  LIMIT :limit OFFSET :offset
');
$stmt->execute([':limit' => $pageSize + 1, ':offset' => $offset]);
$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

$hasMore = count($files) > $pageSize;
$files = array_slice($files, 0, $pageSize);

header('Cache-Control: public, max-age=30, stale-while-revalidate=300');
header('Vary: Accept-Encoding');
header('X-Next-Page-Token: ' . ($hasMore ? (string) ($offset + $pageSize) : ''));

foreach ($files as $row) {
  $id = htmlspecialchars($row['local_key'], ENT_QUOTES, 'UTF-8');
  $name = htmlspecialchars($row['file_name'], ENT_QUOTES, 'UTF-8');
  $kind = htmlspecialchars($row['kind'], ENT_QUOTES, 'UTF-8');
  $mimeType = htmlspecialchars($row['mime_type'], ENT_QUOTES, 'UTF-8');
  $previewSrc = htmlspecialchars($row['preview_data_uri'] ?: './thumb.php?photo=' . rawurlencode($row['local_key']), ENT_QUOTES, 'UTF-8');

  $thumbSrc = $row['kind'] === 'video'
    ? htmlspecialchars((string) $s3->createPresignedRequest($s3->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => getThumbObjectKey($row)]), '+10 minutes')->getUri(), ENT_QUOTES, 'UTF-8')
    : htmlspecialchars('./thumb.php?photo=' . rawurlencode($row['local_key']), ENT_QUOTES, 'UTF-8');

  echo '<button type="button" data-index="" data-id="' . $id . '" data-name="' . $name . '" data-kind="' . $kind . '" data-mime-type="' . $mimeType . '" data-thumb-src="' . $thumbSrc . '" class="gallery-tile">';
  if ($row['kind'] === 'video') {
    echo '<div class="gallery-video-wrap">';
    echo '<img src="' . $thumbSrc . '" alt="' . $name . '" class="gallery-tile-media" loading="lazy" decoding="async" fetchpriority="low">';
    echo '<div class="gallery-video-overlay"></div>';
    echo '<div class="gallery-video-center">';
    echo '<div class="gallery-video-icon-wrap">';
    echo '<svg viewBox="0 0 24 24" aria-hidden="true" class="h-5 w-5 gallery-video-play"><path d="M8 5.5v13l11-6.5-11-6.5Z"/></svg>';
    echo '</div></div></div>';
  }
  else {
    echo '<img src="' . $previewSrc . '" data-thumb-src="' . $thumbSrc . '" alt="' . $name . '" class="gallery-tile-media" loading="lazy" decoding="async" fetchpriority="low">';
  }
  echo '</button>';
}