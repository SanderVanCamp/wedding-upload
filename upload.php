<?php
require_once __DIR__ . '/request_guard.php';
guardRequest(['POST'], true);
applySecurityHeaders();
require_once 'vendor/autoload.php';
require_once __DIR__ . '/env.php';
loadEnvFile('/var/www/wedding-upload/.env');
use Aws\S3\S3Client;

function generateResizedJpeg(string $sourcePath, int $maxSize, int $quality): ?string
{
  $info = @getimagesize($sourcePath);
  if (!$info || !isset($info[2])) {
    return null;
  }

  $source = match ($info[2]) {
    IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
    IMAGETYPE_PNG => @imagecreatefrompng($sourcePath),
    IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
    default => false,
  };

  if (!$source) {
    return null;
  }

  $width = imagesx($source);
  $height = imagesy($source);
  if ($width <= 0 || $height <= 0) {
    imagedestroy($source);
    return null;
  }

  $scale = min($maxSize / $width, $maxSize / $height, 1);
  $targetWidth = max(1, (int) round($width * $scale));
  $targetHeight = max(1, (int) round($height * $scale));

  $thumb = imagecreatetruecolor($targetWidth, $targetHeight);
  $white = imagecolorallocate($thumb, 255, 255, 255);
  imagefill($thumb, 0, 0, $white);

  if ($info[2] === IMAGETYPE_PNG) {
    imagealphablending($thumb, false);
    imagesavealpha($thumb, true);
    $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
    imagefill($thumb, 0, 0, $transparent);
  }

  imagecopyresampled($thumb, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

  ob_start();
  imagejpeg($thumb, null, $quality);
  $jpeg = ob_get_clean();
  imagedestroy($thumb);
  imagedestroy($source);
  return $jpeg === false ? null : $jpeg;
}

function generateResizedJpegFromImage($source, int $sourceType, int $maxSize, int $quality): ?string
{
  if (!$source) {
    return null;
  }

  $width = imagesx($source);
  $height = imagesy($source);
  if ($width <= 0 || $height <= 0) {
    return null;
  }

  $scale = min($maxSize / $width, $maxSize / $height, 1);
  $targetWidth = max(1, (int) round($width * $scale));
  $targetHeight = max(1, (int) round($height * $scale));

  $thumb = imagecreatetruecolor($targetWidth, $targetHeight);
  $white = imagecolorallocate($thumb, 255, 255, 255);
  imagefill($thumb, 0, 0, $white);

  if ($sourceType === IMAGETYPE_PNG || $sourceType === IMAGETYPE_WEBP) {
    imagealphablending($thumb, false);
    imagesavealpha($thumb, true);
    $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
    imagefill($thumb, 0, 0, $transparent);
  }

  imagecopyresampled($thumb, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

  ob_start();
  imagejpeg($thumb, null, $quality);
  $jpeg = ob_get_clean();
  imagedestroy($thumb);
  return $jpeg === false ? null : $jpeg;
}

function generateImageThumbnail(string $sourcePath): ?string
{
  $jpeg = generateResizedJpeg($sourcePath, 300, 72);
  if ($jpeg === null) {
    return null;
  }

  return $jpeg;
}

function generateTinyPreviewDataUri(string $sourcePath): ?string
{
  $jpeg = generateResizedJpeg($sourcePath, 24, 35);
  if ($jpeg === null) {
    return null;
  }

  return 'data:image/jpeg;base64,' . base64_encode($jpeg);
}

function generateVideoThumbnailJpeg(string $sourcePath, int $maxSize = 600, int $quality = 72): ?string
{
  $ffmpeg = trim((string) shell_exec('command -v ffmpeg 2>/dev/null'));
  if ($ffmpeg === '') {
    return null;
  }

  $outputPath = tempnam(sys_get_temp_dir(), 'video-thumb-');
  if ($outputPath === false) {
    return null;
  }
  $jpegPath = $outputPath . '.jpg';
  @rename($outputPath, $jpegPath);

  $escapedFfmpeg = escapeshellarg($ffmpeg);
  $escapedInput = escapeshellarg($sourcePath);
  $escapedOutput = escapeshellarg($jpegPath);
  $filter = "scale='min($maxSize\\,iw)':-2";
  $command = $escapedFfmpeg . ' -y -i ' . $escapedInput . ' -ss 1 -frames:v 1 -vf ' . escapeshellarg($filter) . ' -q:v ' . (int) $quality . ' ' . $escapedOutput . ' 2>/dev/null';

  $output = [];
  $exitCode = 0;
  exec($command, $output, $exitCode);

  $jpeg = ($exitCode === 0 && file_exists($jpegPath) && filesize($jpegPath) > 0) ? file_get_contents($jpegPath) : false;
  @unlink($jpegPath);

  return $jpeg === false ? null : $jpeg;
}

function rotateImageByExifOrientation($image, int $orientation)
{
  if (!$image) {
    return $image;
  }

  return match ($orientation) {
    3 => imagerotate($image, 180, 0),
    6 => imagerotate($image, -90, 0),
    8 => imagerotate($image, 90, 0),
    default => $image,
  };
}

function flipImageForExifOrientation($image, int $orientation)
{
  if (!$image || !function_exists('imageflip')) {
    return $image;
  }

  if ($orientation === 2) {
    imageflip($image, IMG_FLIP_HORIZONTAL);
  } elseif ($orientation === 4) {
    imageflip($image, IMG_FLIP_VERTICAL);
  } elseif ($orientation === 5 || $orientation === 7) {
    imageflip($image, IMG_FLIP_HORIZONTAL);
  }

  return $image;
}

function applyExifOrientation($sourcePath, $image)
{
  if (!$image || !function_exists('exif_read_data')) {
    return $image;
  }

  $info = @exif_read_data($sourcePath);
  $orientation = (int) ($info['Orientation'] ?? 1);
  if ($orientation <= 1) {
    return $image;
  }

  if (in_array($orientation, [2, 4, 5, 7], true) && function_exists('imageflip')) {
    $image = flipImageForExifOrientation($image, $orientation);
  }

  if (in_array($orientation, [5, 6, 7, 8], true)) {
    $rotated = rotateImageByExifOrientation($image, $orientation);
    return $rotated ?: $image;
  }

  return $image;
}

function decodeImageSource(string $sourcePath)
{
  $info = @getimagesize($sourcePath);
  if (!$info || !isset($info[2])) {
  return [null, null];
}

function cleanupImageResource($image): void
{
  if ($image) {
    imagedestroy($image);
  }
}

  $source = match ($info[2]) {
    IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
    IMAGETYPE_PNG => @imagecreatefrompng($sourcePath),
    IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
    default => false,
  };

  $source = applyExifOrientation($sourcePath, $source);
  return [$source ?: null, $info[2]];
}

function detectExtension(string $fileName, string $fileMime): string
{
  $pathInfo = pathinfo($fileName);
  $ext = strtolower($pathInfo['extension'] ?? '');
  if ($ext !== '') {
    return preg_replace('/[^A-Za-z0-9]+/', '', $ext) ?: 'bin';
  }

  return match (true) {
    str_starts_with($fileMime, 'image/jpeg') => 'jpg',
    str_starts_with($fileMime, 'image/png') => 'png',
    str_starts_with($fileMime, 'image/webp') => 'webp',
    str_starts_with($fileMime, 'video/mp4') => 'mp4',
    str_starts_with($fileMime, 'video/quicktime') => 'mov',
    default => 'bin',
  };
}

function resizeImageForUpload(string $sourcePath, int $maxSize = 1600): ?string
{
  $info = @getimagesize($sourcePath);
  if (!$info || !isset($info[2])) {
    return null;
  }

  $source = match ($info[2]) {
    IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
    IMAGETYPE_PNG => @imagecreatefrompng($sourcePath),
    IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
    default => false,
  };

  if (!$source) {
    return null;
  }

  $width = imagesx($source);
  $height = imagesy($source);
  if ($width <= 0 || $height <= 0) {
    imagedestroy($source);
    return null;
  }

  $scale = min($maxSize / $width, $maxSize / $height, 1);
  $targetWidth = max(1, (int) round($width * $scale));
  $targetHeight = max(1, (int) round($height * $scale));

  if ($targetWidth === $width && $targetHeight === $height) {
    ob_start();
    imagejpeg($source, null, 90);
    $jpeg = ob_get_clean();
    imagedestroy($source);
    return $jpeg === false ? null : $jpeg;
  }

  $resized = imagecreatetruecolor($targetWidth, $targetHeight);

  if ($info[2] === IMAGETYPE_PNG || $info[2] === IMAGETYPE_WEBP) {
    imagealphablending($resized, false);
    imagesavealpha($resized, true);
    $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
    imagefill($resized, 0, 0, $transparent);
  } else {
    $white = imagecolorallocate($resized, 255, 255, 255);
    imagefill($resized, 0, 0, $white);
  }

  imagecopyresampled($resized, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

  ob_start();
  imagejpeg($resized, null, 90);
  $jpeg = ob_get_clean();
  imagedestroy($resized);
  imagedestroy($source);

  return $jpeg === false ? null : $jpeg;
}

function getDb(): PDO
{
  $dbPath = __DIR__ . '/media.sqlite';
  $pdo = new PDO('sqlite:' . $dbPath);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $tableExists = (bool) $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='uploads'")->fetchColumn();
  if ($tableExists) {
    $columns = [];
    foreach ($pdo->query('PRAGMA table_info(uploads)') as $column) {
      $columns[$column['name']] = $column;
    }

    $legacyLayout = isset($columns['drive_file_id']) || !isset($columns['object_key']);
    if ($legacyLayout) {
      $pdo->exec('ALTER TABLE uploads RENAME TO uploads_legacy');
      $pdo->exec('
        CREATE TABLE uploads (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          local_key TEXT NOT NULL UNIQUE,
          file_name TEXT NOT NULL,
          mime_type TEXT NOT NULL,
          kind TEXT NOT NULL,
          object_key TEXT NOT NULL,
          preview_data_uri TEXT,
          thumb_object_key TEXT,
          thumb_width INTEGER,
          thumb_height INTEGER,
          created_at TEXT NOT NULL
        )
      ');
    } else {
      foreach (['preview_data_uri', 'thumb_width', 'thumb_height'] as $columnName) {
        if (!isset($columns[$columnName])) {
          $columnType = in_array($columnName, ['thumb_width', 'thumb_height'], true) ? 'INTEGER' : 'TEXT';
          $pdo->exec('ALTER TABLE uploads ADD COLUMN ' . $columnName . ' ' . $columnType);
        }
      }
    }
  } else {
    $pdo->exec('
      CREATE TABLE uploads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        local_key TEXT NOT NULL UNIQUE,
        file_name TEXT NOT NULL,
        mime_type TEXT NOT NULL,
        kind TEXT NOT NULL,
        object_key TEXT NOT NULL,
        preview_data_uri TEXT,
        thumb_object_key TEXT,
        thumb_width INTEGER,
        thumb_height INTEGER,
        created_at TEXT NOT NULL
      )
    ');
  }

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

function getBucketName(): string
{
  $bucket = getenv('HETZNER_S3_BUCKET');
  if ($bucket === false || $bucket === '') {
    header('HTTP/1.1 500 Internal Server Error');
    exit('Missing HETZNER_S3_BUCKET');
  }
  return $bucket;
}

// --- STEP 2: Handle Finalization (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
  if (!str_starts_with($contentType, 'multipart/form-data')) {
    header('HTTP/1.1 415 Unsupported Media Type');
    echo 'Expected multipart/form-data';
    exit;
  }

  $fileName = $_FILES['file']['name'] ?? ($_SERVER['HTTP_UPLOAD_FILENAME'] ?? ('wedding-upload-' . time()));
  $filePath = $_FILES['file']['tmp_name'] ?? '';

  if (empty($filePath) || !file_exists($filePath)) {
    header('HTTP/1.1 400 Bad Request');
    echo "Error: File source not found. Path: " . ($filePath ?: 'EMPTY');
    exit;
  }

  $fileMime = '';
  if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
      $fileMime = finfo_file($finfo, $filePath) ?: '';
      finfo_close($finfo);
    }
  }

  if ($fileMime === '') {
    $fileMime = mime_content_type($filePath) ?: ($_FILES['file']['type'] ?? 'application/octet-stream');
  }

  $localKey = sha1($fileName . '|' . ($filePath ?: '') . '|' . microtime(true));
  $extension = detectExtension($fileName, $fileMime);
  $objectKey = 'uploads/originals/' . $localKey . '.' . $extension;

  if (!str_starts_with($fileMime, 'image/') && !str_starts_with($fileMime, 'video/')) {
    header('HTTP/1.1 415 Unsupported Media Type');
    echo 'Only images and videos are allowed';
    exit;
  }

  try {
    $db = getDb();
    $kind = str_starts_with($fileMime, 'video/') ? 'video' : 'image';
    $s3 = getS3Client();
    $bucket = getBucketName();
    $previewDataUri = null;
    $thumbObjectKey = null;
    $thumbWidth = null;
    $thumbHeight = null;
    $displayObjectKey = null;
    $s3->putObject([
      'Bucket' => $bucket,
      'Key' => $objectKey,
      'SourceFile' => $filePath,
      'ContentType' => $fileMime,
      'CacheControl' => 'public, max-age=31536000, immutable',
      'ACL' => 'private',
    ]);

    if ($kind === 'image') {
      [$sourceImage, $sourceType] = decodeImageSource($filePath);
      $previewJpeg = $sourceImage ? generateResizedJpegFromImage($sourceImage, $sourceType, 24, 35) : null;
      $previewDataUri = $previewJpeg !== null ? ('data:image/jpeg;base64,' . base64_encode($previewJpeg)) : null;
      $displayBody = $sourceImage ? generateResizedJpegFromImage($sourceImage, $sourceType, 1200, 90) : null;
      if ($displayBody !== null) {
        $displayObjectKey = 'uploads/display/' . $localKey . '.jpg';
        $s3->putObject([
          'Bucket' => $bucket,
          'Key' => $displayObjectKey,
          'Body' => $displayBody,
          'ContentType' => 'image/jpeg',
          'CacheControl' => 'public, max-age=31536000, immutable',
          'ACL' => 'private',
        ]);

        $thumbBody = $sourceImage ? generateResizedJpegFromImage($sourceImage, $sourceType, 300, 72) : null;
        if ($thumbBody === null) {
          $thumbBody = $displayBody;
        }
        cleanupImageResource($sourceImage);
      } else {
        $thumbBody = $sourceImage ? generateResizedJpegFromImage($sourceImage, $sourceType, 300, 72) : null;
        cleanupImageResource($sourceImage);
      }

      if ($thumbBody !== null) {
        $thumbObjectKey = 'uploads/thumbs/' . $localKey . '.jpg';
        $thumbInfo = @getimagesizefromstring($thumbBody);
        if ($thumbInfo && isset($thumbInfo[0], $thumbInfo[1])) {
          $thumbWidth = (int) $thumbInfo[0];
          $thumbHeight = (int) $thumbInfo[1];
        }
        $s3->putObject([
          'Bucket' => $bucket,
          'Key' => $thumbObjectKey,
          'Body' => $thumbBody,
          'ContentType' => 'image/jpeg',
          'CacheControl' => 'public, max-age=31536000, immutable',
          'ACL' => 'private',
        ]);
      }
    } else {
      $thumbBody = generateVideoThumbnailJpeg($filePath, 300, 72);
      if ($thumbBody !== null) {
        $thumbObjectKey = 'uploads/thumbs/' . $localKey . '.jpg';
        $thumbInfo = @getimagesizefromstring($thumbBody);
        if ($thumbInfo && isset($thumbInfo[0], $thumbInfo[1])) {
          $thumbWidth = (int) $thumbInfo[0];
          $thumbHeight = (int) $thumbInfo[1];
        }
        $s3->putObject([
          'Bucket' => $bucket,
          'Key' => $thumbObjectKey,
          'Body' => $thumbBody,
          'ContentType' => 'image/jpeg',
          'CacheControl' => 'public, max-age=31536000, immutable',
          'ACL' => 'private',
        ]);
      }
    }

    $stmt = $db->prepare('
      INSERT OR REPLACE INTO uploads
        (local_key, file_name, mime_type, kind, object_key, preview_data_uri, thumb_object_key, thumb_width, thumb_height, created_at)
      VALUES
        (:local_key, :file_name, :mime_type, :kind, :object_key, :preview_data_uri, :thumb_object_key, :thumb_width, :thumb_height, :created_at)
    ');
    $stmt->execute([
      ':local_key' => $localKey,
      ':file_name' => $fileName,
      ':mime_type' => $fileMime,
      ':kind' => $kind,
      ':object_key' => $objectKey,
      ':preview_data_uri' => $previewDataUri,
      ':thumb_object_key' => $thumbObjectKey,
      ':thumb_width' => $thumbWidth,
      ':thumb_height' => $thumbHeight,
      ':created_at' => gmdate('c'),
    ]);

    if ($isChunked && file_exists($filePath)) {
      unlink($filePath);
    }
    header('Content-Type: application/json');
    echo json_encode([
      'success' => true,
      'objectKey' => $objectKey,
      'previewDataUri' => $previewDataUri,
      'thumbObjectKey' => $thumbObjectKey,
      'displayObjectKey' => $displayObjectKey,
    ]);

  } catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo "Hetzner Object Storage Error: " . $e->getMessage();
  }
  exit;
}
