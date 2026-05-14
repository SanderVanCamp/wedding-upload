<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/env.php';
loadEnvFile('/var/www/wedding-upload/.env');

use Aws\S3\S3Client;

$photoId = trim((string) ($_GET['photo'] ?? ''));
$fallbackTitle = 'Sander & Silvie';
$fallbackDescription = "Heb je foto's genomen op ons trouwfeest? Deel ze hier met ons, zodat we samen nog eens kunnen nagenieten van die mooie dag.";
$fallbackImage = '/share/share.jpg';
$pageUrl = 'https://vooraltijdmijnliefje.be/share.php';
$shareImage = $fallbackImage;
$title = $fallbackTitle;
$description = $fallbackDescription;
$galleryUrl = '/index.html';

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

function presignObjectUrl(S3Client $s3, string $bucket, string $key, int $minutes = 60): string
{
  $command = $s3->getCommand('GetObject', [
    'Bucket' => $bucket,
    'Key' => $key,
  ]);
  $request = $s3->createPresignedRequest($command, '+' . $minutes . ' minutes');
  return (string) $request->getUri();
}

if ($photoId !== '' && preg_match('/^[a-f0-9]{40}$/', $photoId)) {
  $dbPath = __DIR__ . '/media.sqlite';
  if (file_exists($dbPath)) {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $stmt = $db->prepare('
      SELECT local_key, file_name, mime_type, kind, object_key, preview_data_uri, thumb_object_key, created_at
      FROM uploads
      WHERE local_key = :local_key
      LIMIT 1
    ');
    $stmt->execute([':local_key' => $photoId]);
    $row = $stmt->fetch();

    if ($row) {
      $s3 = getS3Client();
      $bucket = getBucketName();
      $displayObjectKey = str_replace('/originals/', '/display/', $row['object_key']);
      $thumbObjectKey = $row['thumb_object_key'] ?: $displayObjectKey;
      $shareImage = $row['thumb_object_key']
        ? presignObjectUrl($s3, $bucket, $thumbObjectKey, 60)
        : presignObjectUrl($s3, $bucket, $displayObjectKey, 60);
      $title = $row['file_name'] ?: $fallbackTitle;
      $description = $row['kind'] === 'video'
        ? 'Bekijk deze video uit ons trouwalbum.'
        : 'Bekijk deze foto uit ons trouwalbum.';
      $galleryUrl = '/index.html#photo=' . rawurlencode($photoId);
      $pageUrl = 'https://vooraltijdmijnliefje.be/share.php?photo=' . rawurlencode($photoId);
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
  <meta property="og:type" content="website">
  <meta property="og:url" content="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:title" content="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:description" content="<?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:image" content="<?= htmlspecialchars($shareImage, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta http-equiv="refresh" content="0;url=<?= htmlspecialchars($galleryUrl, ENT_QUOTES, 'UTF-8') ?>">
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
</head>
<body>
  <script>
    window.location.replace(<?= json_encode($galleryUrl) ?>);
  </script>
</body>
</html>
