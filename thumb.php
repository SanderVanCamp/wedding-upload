<?php
require_once __DIR__ . '/request_guard.php';
guardRequest(['GET', 'HEAD']);
applySecurityHeaders();
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/media_helpers.php';
loadEnvFile('/var/www/wedding-upload/.env');

use Aws\S3\S3Client;

$photoId = trim((string) ($_GET['photo'] ?? ''));
$variant = trim((string) ($_GET['variant'] ?? 'thumb'));
if ($photoId === '' || !preg_match('/^[a-f0-9]{40}$/', $photoId)) {
  header('HTTP/1.1 400 Bad Request');
  exit('Invalid photo id');
}

$dbPath = __DIR__ . '/media.sqlite';
if (!file_exists($dbPath)) {
  header('HTTP/1.1 404 Not Found');
  exit('Photo not found');
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

$stmt = $db->prepare('SELECT object_key, thumb_object_key FROM uploads WHERE local_key = :local_key LIMIT 1');
$stmt->execute([':local_key' => $photoId]);
$row = $stmt->fetch();
if (!$row) {
  header('HTTP/1.1 404 Not Found');
  exit('Photo not found');
}

$s3 = getS3Client();
$bucket = getBucketName();
$displayObjectKey = getDisplayObjectKey($row['object_key']);
$thumbObjectKey = getThumbObjectKey($row);
$target = $variant === 'display' ? $displayObjectKey : $thumbObjectKey;
$url = presignObjectUrl($s3, $bucket, $target, 60);

header('Location: ' . $url, true, 302);
