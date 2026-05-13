<?php
require_once 'vendor/autoload.php';
use Aws\S3\S3Client;

function getDb(): PDO
{
  $dbPath = __DIR__ . '/media.sqlite';
  $pdo = new PDO('sqlite:' . $dbPath);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  return $pdo;
}

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

function loadUploadRow(string $localKey): array
{
  $db = getDb();
  $stmt = $db->prepare('
    SELECT local_key, mime_type, kind, object_key, thumb_object_key
    FROM uploads
    WHERE local_key = :id
    LIMIT 1
  ');
  $stmt->execute([':id' => $localKey]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    throw new Exception('Media not found');
  }
  return $row;
}

function sendPlaceholder(): never
{
  header('Content-Type: image/svg+xml');
  header('Cache-Control: public, max-age=300');
  echo <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="1200" viewBox="0 0 1200 1200">
  <rect width="1200" height="1200" fill="#f4efe9"/>
  <rect x="120" y="120" width="960" height="960" rx="64" fill="#efe6dd" stroke="#d8cbbd" stroke-width="8"/>
  <path d="M360 720l120-140 120 110 100-90 140 120" fill="none" stroke="#7b6b5d" stroke-width="24" stroke-linecap="round" stroke-linejoin="round"/>
  <circle cx="470" cy="450" r="48" fill="#7b6b5d"/>
</svg>
SVG;
  exit;
}

$fileId = $_GET['id'] ?? '';
if ($fileId === '') {
  header('HTTP/1.1 400 Bad Request');
  exit('Missing file id');
}

$variant = $_GET['variant'] ?? 'full';

try {
  $row = loadUploadRow($fileId);
} catch (Exception $e) {
  header('HTTP/1.1 404 Not Found');
  exit($e->getMessage());
}

$bucket = getenv('HETZNER_S3_BUCKET');
if ($bucket === false || $bucket === '') {
  header('HTTP/1.1 500 Internal Server Error');
  exit('Missing HETZNER_S3_BUCKET');
}

$key = match ($variant) {
  'thumb' => !empty($row['thumb_object_key']) ? $row['thumb_object_key'] : $row['object_key'],
  'display' => !empty($row['object_key']) ? str_replace('/originals/', '/display/', $row['object_key']) : '',
  default => $row['object_key'],
};
if (!$key) {
  sendPlaceholder();
}

$s3 = getS3Client();

try {
  $result = $s3->getObject([
    'Bucket' => $bucket,
    'Key' => $key,
  ]);
} catch (Exception $e) {
  sendPlaceholder();
}

header('Content-Type: ' . ($row['mime_type'] ?: 'application/octet-stream'));
header('Cache-Control: public, max-age=3600');
echo (string) $result['Body'];
