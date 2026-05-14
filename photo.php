<?php
require_once __DIR__ . '/request_guard.php';
guardRequest(['GET', 'HEAD']);
applySecurityHeaders();
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/env.php';
loadEnvFile('/var/www/wedding-upload/.env');

use Aws\S3\S3Client;

$photoId = trim((string) ($_GET['photo'] ?? ''));
if ($photoId === '' || !preg_match('/^[a-f0-9]{40}$/', $photoId)) {
  header('HTTP/1.1 400 Bad Request');
  header('Content-Type: application/json');
  echo json_encode(['error' => 'Invalid photo id']);
  exit;
}

$dbPath = __DIR__ . '/media.sqlite';
if (!file_exists($dbPath)) {
  header('HTTP/1.1 404 Not Found');
  header('Content-Type: application/json');
  echo json_encode(['error' => 'Photo not found']);
  exit;
}

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function getS3Client(): S3Client
{
  static $client = null;
  if ($client instanceof S3Client) {
    return $client;
  }

  $region = getenv('HETZNER_S3_REGION') ?: 'us-east-1';
  $endpoint = getenv('HETZNER_S3_ENDPOINT');
  if ($endpoint === false || $endpoint === '') {
    header('HTTP/1.1 500 Internal Server Error');
    exit('Missing HETZNER_S3_ENDPOINT');
  }
  if (!preg_match('#^https?://#i', $endpoint)) {
    $endpoint = 'https://' . $endpoint;
  }

  $client = new S3Client([
    'version' => 'latest',
    'region' => $region,
    'endpoint' => $endpoint,
    'use_path_style_endpoint' => true,
    'credentials' => [
      'key' => getenv('HETZNER_S3_ACCESS_KEY_ID'),
      'secret' => getenv('HETZNER_S3_SECRET_ACCESS_KEY'),
    ],
  ]);

  return $client;
}

function getBucketName(): string
{
  static $bucket = null;
  if (is_string($bucket) && $bucket !== '') {
    return $bucket;
  }

  $bucket = getenv('HETZNER_S3_BUCKET');
  if ($bucket === false || $bucket === '') {
    header('HTTP/1.1 500 Internal Server Error');
    exit('Missing HETZNER_S3_BUCKET');
  }
  return $bucket;
}

function presignObjectUrl(S3Client $s3, string $bucket, string $key, int $minutes = 60): string
{
  $command = $s3->getCommand('GetObject', [
    'Bucket' => $bucket,
    'Key' => $key,
  ]);
  $request = $s3->createPresignedRequest($command, '+' . $minutes . ' minutes');
  return (string) $request->getUri();
}

$stmt = $db->prepare('
  SELECT local_key, file_name, mime_type, kind, object_key, preview_data_uri, thumb_object_key, created_at
  FROM uploads
  WHERE local_key = :local_key
  LIMIT 1
');
$stmt->execute([':local_key' => $photoId]);
$row = $stmt->fetch();
if (!$row) {
  header('HTTP/1.1 404 Not Found');
  header('Content-Type: application/json');
  echo json_encode(['error' => 'Photo not found']);
  exit;
}

$s3 = getS3Client();
$bucket = getBucketName();
$displayObjectKey = str_replace('/originals/', '/display/', $row['object_key']);
$thumbObjectKey = $row['thumb_object_key'] ?: $displayObjectKey;

header('Content-Type: application/json');
echo json_encode([
  'id' => $row['local_key'],
  'name' => $row['file_name'],
  'kind' => $row['kind'],
  'mimeType' => $row['mime_type'],
  'src' => presignObjectUrl($s3, $bucket, $row['object_key']),
  'displaySrc' => presignObjectUrl($s3, $bucket, $displayObjectKey),
  'thumbSrc' => presignObjectUrl($s3, $bucket, $thumbObjectKey),
  'previewDataUri' => $row['preview_data_uri'],
]);
