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

$photoId = trim((string) ($_GET['photo'] ?? ''));
$fallbackTitle = 'Sander & Silvie';
$fallbackDescription = "Heb je foto's genomen op ons trouwfeest? Deel ze hier met ons, zodat we samen nog eens kunnen nagenieten van die mooie dag.";
$fallbackImage = '/share/share.jpg';
$fallbackImageWidth = 1200;
$fallbackImageHeight = 630;
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'vooraltijdmijnliefje.be';
$baseUrl = $scheme . '://' . $host;
$pageUrl = $baseUrl . '/share.php';
$shareImage = $fallbackImage;
$title = $fallbackTitle;
$description = $fallbackDescription;
$galleryUrl = '/index.php';
$imageWidth = $fallbackImageWidth;
$imageHeight = $fallbackImageHeight;

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

if ($photoId === '' || !preg_match('/^[a-f0-9]{40}$/', $photoId)) {
  header('Content-Type: text/html; charset=UTF-8');
  header('Cache-Control: public, max-age=60');
} else {
  $dbPath = __DIR__ . '/media.sqlite';
  $db = getReadDb($dbPath);
  if ($db) {
    $columns = getUploadsColumnMap($dbPath);
    $hasThumbDimensions = isset($columns['thumb_width'], $columns['thumb_height']);

    $stmt = $db->prepare('
      SELECT local_key, file_name, mime_type, kind, object_key, preview_data_uri, thumb_object_key' . ($hasThumbDimensions ? ', thumb_width, thumb_height' : '') . ', created_at
      FROM uploads
      WHERE local_key = :local_key
      LIMIT 1
    ');
    $stmt->execute([':local_key' => $photoId]);
    $row = $stmt->fetch();

    if ($row) {
      $s3 = getS3Client();
      $bucket = getBucketName();
      $displayObjectKey = getDisplayObjectKey($row['object_key']);
      $shareImage = presignObjectUrl($s3, $bucket, $displayObjectKey, 60);
      $imageWidth = $fallbackImageWidth;
      $imageHeight = $fallbackImageHeight;
      $title = $row['file_name'] ?: $fallbackTitle;
      $description = $row['kind'] === 'video'
        ? 'Bekijk deze video uit ons trouwalbum.'
        : 'Bekijk deze foto uit ons trouwalbum.';
      $galleryUrl = '/index.php?photo=' . rawurlencode($photoId);
      $pageUrl = $baseUrl . '/share.php?photo=' . rawurlencode($photoId);
    }
  }
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: public, max-age=60');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, notranslate">
  <meta property="og:type" content="website">
  <meta property="og:url" content="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:title" content="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:description" content="<?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:image" content="<?= htmlspecialchars($shareImage, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:image:width" content="<?= (int) ($imageWidth ?? $fallbackImageWidth) ?>">
  <meta property="og:image:height" content="<?= (int) ($imageHeight ?? $fallbackImageHeight) ?>">
  <meta http-equiv="refresh" content="0;url=<?= htmlspecialchars($galleryUrl, ENT_QUOTES, 'UTF-8') ?>">
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
</head>
<body>
  <script>
    window.location.replace(<?= json_encode($galleryUrl) ?>);
  </script>
</body>
</html>
