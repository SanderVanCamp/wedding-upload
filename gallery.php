<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/env.php';
loadEnvFile('/var/www/wedding-upload/.env');

use Aws\S3\S3Client;

$pageToken = $_GET['pageToken'] ?? '0';
$pageSize = 24;
$offset = max(0, (int) $pageToken);
$queryLimit = $pageSize + 1;

$dbPath = __DIR__ . '/media.sqlite';
if (!file_exists($dbPath)) {
  header('X-Next-Page-Token: ');
  exit;
}

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function getS3Client(): S3Client
{
  $region = getenv('HETZNER_S3_REGION') ?: 'us-east-1';
  $endpoint = getenv('HETZNER_S3_ENDPOINT');
  if ($endpoint === false || $endpoint === '') {
    header('HTTP/1.1 500 Internal Server Error');
    exit('Missing HETZNER_S3_ENDPOINT');
  }
  if (!preg_match('#^https?://#i', $endpoint)) {
    $endpoint = 'https://' . $endpoint;
  }

  return new S3Client([
    'version' => 'latest',
    'region' => $region,
    'endpoint' => $endpoint,
    'use_path_style_endpoint' => true,
    'credentials' => [
      'key' => getenv('HETZNER_S3_ACCESS_KEY_ID'),
      'secret' => getenv('HETZNER_S3_SECRET_ACCESS_KEY'),
    ],
  ]);
}

function getBucketName(): string
{
  $bucket = getenv('HETZNER_S3_BUCKET');
  if ($bucket === false || $bucket === '') {
    header('HTTP/1.1 500 Internal Server Error');
    exit('Missing HETZNER_S3_BUCKET');
  }
  return $bucket;
}

function presignObjectUrl(S3Client $s3, string $bucket, string $key, int $minutes = 10): string
{
  $command = $s3->getCommand('GetObject', [
    'Bucket' => $bucket,
    'Key' => $key,
  ]);
  $request = $s3->createPresignedRequest($command, '+' . $minutes . ' minutes');
  return (string) $request->getUri();
}

$s3 = getS3Client();
$bucket = getBucketName();

$stmt = $db->prepare('
  SELECT local_key, file_name, mime_type, kind, object_key, preview_data_uri, thumb_object_key, created_at
  FROM uploads
  ORDER BY datetime(created_at) DESC
  LIMIT :limit OFFSET :offset
');
$stmt->bindValue(':limit', $queryLimit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$files = $stmt->fetchAll(PDO::FETCH_ASSOC);
$hasMore = count($files) > $pageSize;
if ($hasMore) {
  $files = array_slice($files, 0, $pageSize);
}

header('Cache-Control: public, max-age=30, stale-while-revalidate=300');
header('Vary: Accept-Encoding');
header('X-Next-Page-Token: ' . ($hasMore ? (string) ($offset + $pageSize) : ''));

foreach ($files as $row) {
  $id = htmlspecialchars($row['local_key'], ENT_QUOTES, 'UTF-8');
  $name = htmlspecialchars($row['file_name'], ENT_QUOTES, 'UTF-8');
  $kind = htmlspecialchars($row['kind'], ENT_QUOTES, 'UTF-8');
  $mimeType = htmlspecialchars($row['mime_type'], ENT_QUOTES, 'UTF-8');
  $fullSrc = presignObjectUrl($s3, $bucket, $row['object_key']);
  $displayObjectKey = str_replace('/originals/', '/display/', $row['object_key']);
  $displaySrc = presignObjectUrl($s3, $bucket, $displayObjectKey);
  $thumbObjectKey = $row['thumb_object_key'] ?: $displayObjectKey;
  $thumbSrc = presignObjectUrl($s3, $bucket, $thumbObjectKey);
  $previewSrc = htmlspecialchars($row['preview_data_uri'] ?: $thumbSrc, ENT_QUOTES, 'UTF-8');
  $src = htmlspecialchars($fullSrc, ENT_QUOTES, 'UTF-8');
  $displaySrc = htmlspecialchars($displaySrc, ENT_QUOTES, 'UTF-8');
  $thumbSrc = htmlspecialchars($thumbSrc, ENT_QUOTES, 'UTF-8');

  echo '<figure class="overflow-hidden">';
  echo '<button type="button" data-index="" data-id="' . $id . '" data-name="' . $name . '" data-kind="' . $kind . '" data-mime-type="' . $mimeType . '" data-src="' . $src . '" data-display-src="' . $displaySrc . '" data-thumb-src="' . $thumbSrc . '" class="block w-full text-left">';
  if ($row['kind'] === 'video') {
    echo '<div class="relative aspect-[1/1] w-full overflow-hidden bg-black">';
    echo '<div class="absolute inset-0 bg-[#120f0d]"></div>';
    echo '<div class="absolute inset-0 flex items-center justify-center text-white/90">';
    echo '<span class="rounded-full bg-black/55 px-3 py-1 text-xs font-medium tracking-wide backdrop-blur-sm">Video</span>';
    echo '</div></div>';
  } else {
    echo '<img src="' . $previewSrc . '" data-thumb-src="' . $thumbSrc . '" alt="' . $name . '" class="aspect-[1/1] w-full object-cover" loading="lazy" decoding="async" fetchpriority="low">';
  }
  echo '</button></figure>';
}
