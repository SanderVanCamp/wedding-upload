<?php
require_once 'vendor/autoload.php';

function generateImageThumbnail(string $sourcePath, string $destinationPath): void
{
  $info = @getimagesize($sourcePath);
  if (!$info || !isset($info[2])) {
    return;
  }

  $source = match ($info[2]) {
    IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
    IMAGETYPE_PNG => @imagecreatefrompng($sourcePath),
    IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
    default => false,
  };

  if (!$source) {
    return;
  }

  $width = imagesx($source);
  $height = imagesy($source);
  if ($width <= 0 || $height <= 0) {
    imagedestroy($source);
    return;
  }

  $maxSize = 400;
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

  if (!is_dir(dirname($destinationPath))) {
    mkdir(dirname($destinationPath), 0777, true);
  }

  imagejpeg($thumb, $destinationPath, 72);

  imagedestroy($thumb);
  imagedestroy($source);
}

function resizeImageForUpload(string $sourcePath, string $destinationPath, int $maxSize = 1200): bool
{
  $info = @getimagesize($sourcePath);
  if (!$info || !isset($info[2])) {
    return false;
  }

  $source = match ($info[2]) {
    IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
    IMAGETYPE_PNG => @imagecreatefrompng($sourcePath),
    IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
    default => false,
  };

  if (!$source) {
    return false;
  }

  $width = imagesx($source);
  $height = imagesy($source);
  if ($width <= 0 || $height <= 0) {
    imagedestroy($source);
    return false;
  }

  $scale = min($maxSize / $width, $maxSize / $height, 1);
  $targetWidth = max(1, (int) round($width * $scale));
  $targetHeight = max(1, (int) round($height * $scale));

  if ($targetWidth === $width && $targetHeight === $height) {
    imagedestroy($source);
    return false;
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

  $saved = match ($info[2]) {
    IMAGETYPE_JPEG => imagejpeg($resized, $destinationPath, 90),
    IMAGETYPE_PNG => imagepng($resized, $destinationPath, 6),
    IMAGETYPE_WEBP => function_exists('imagewebp') ? imagewebp($resized, $destinationPath, 85) : false,
    default => false,
  };

  imagedestroy($resized);
  imagedestroy($source);

  return (bool) $saved;
}

function uploadFileToDrive(
  Google\Service\Drive $service,
  Google\Client $client,
  Google\Service\Drive\DriveFile $fileMetadata,
  string $filePath,
  string $fileMime,
  int $chunkSizeBytes = 1048576
): string {
  $client->setDefer(TRUE);

  $request = $service->files->create($fileMetadata, [
    'fields' => 'id',
    'supportsAllDrives' => TRUE,
  ]);

  $media = new Google\Http\MediaFileUpload(
    $client,
    $request,
    $fileMime,
    NULL,
    TRUE,
    $chunkSizeBytes
  );

  $media->setFileSize(filesize($filePath));

  $status = FALSE;
  $handle = fopen($filePath, "rb");
  if (!$handle) {
    throw new Exception("Could not open file for reading: " . $filePath);
  }

  while (!$status && !feof($handle)) {
    $chunk = fread($handle, $chunkSizeBytes);
    $status = $media->nextChunk($chunk);
  }
  fclose($handle);

  return $status->id ?? '';
}

function getDb(): PDO
{
  $dbPath = __DIR__ . '/media.sqlite';
  $pdo = new PDO('sqlite:' . $dbPath);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->exec('
    CREATE TABLE IF NOT EXISTS uploads (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      local_key TEXT NOT NULL UNIQUE,
      file_name TEXT NOT NULL,
      mime_type TEXT NOT NULL,
      kind TEXT NOT NULL,
      drive_file_id TEXT NOT NULL,
      thumb_path TEXT,
      display_path TEXT,
      created_at TEXT NOT NULL
    )
  ');
  return $pdo;
}

// 1. Load Credentials from Environment (DDEV .env or Caddyfile)
$folderId = getenv('GOOGLE_DRIVE_FOLDER_ID') ?: '1Iqbwk8q1eU3Pd7seL7lHffQpeNZVe9IT';
$originalFolderId = getenv('GOOGLE_DRIVE_ORIGINALS_FOLDER_ID');
$clientId = getenv('GOOGLE_CLIENT_ID');
$clientSecret = getenv('GOOGLE_CLIENT_SECRET');
$refreshToken = getenv('GOOGLE_REFRESH_TOKEN');

if ($originalFolderId === false || $originalFolderId === '') {
  header('HTTP/1.1 500 Internal Server Error');
  exit('Missing GOOGLE_DRIVE_ORIGINALS_FOLDER_ID');
}

// Path for chunk assembly
$tempDir = __DIR__ . '/tmp/';

// 2. Setup OAuth Client (Acting as your personal account)
$client = new Google\Client();
$client->setClientId($clientId);
$client->setClientSecret($clientSecret);
$client->refreshToken($refreshToken);
$client->addScope(Google\Service\Drive::DRIVE_FILE);

$service = new Google\Service\Drive($client);

// Check if this is a chunked request from FilePond
$patchId = $_SERVER['HTTP_UPLOAD_NAME'] ?? NULL;

// --- STEP 1: Handle Chunks (PATCH) ---
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
  // Ensure the temp directory exists
  if (!is_dir($tempDir)) {
    mkdir($tempDir, 0777, true);
  }

  if (!$patchId) {
    header('HTTP/1.1 400 Bad Request');
    echo "Missing Upload-Name header";
    exit;
  }

  $tempFilePath = $tempDir . $patchId;
  $input = fopen("php://input", "rb");
  $file = fopen($tempFilePath, "ab"); // Append binary mode

  if ($input && $file) {
    stream_copy_to_stream($input, $file);
  }

  fclose($input);
  fclose($file);
  exit;
}

// --- STEP 2: Handle Finalization (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $isChunked = isset($_SERVER['HTTP_UPLOAD_NAME']);
  $fileName = $_SERVER['HTTP_UPLOAD_FILENAME'] ?? ($_FILES['file']['name'] ?? 'wedding-upload-' . time());

  // Determine the source path
  if ($isChunked) {
    $filePath = $tempDir . $_SERVER['HTTP_UPLOAD_NAME'];
  } else {
    $filePath = $_FILES['file']['tmp_name'] ?? '';
  }

  // CRITICAL: Safety check for the "Path must not be empty" error
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

  if (!str_starts_with($fileMime, 'image/') && !str_starts_with($fileMime, 'video/')) {
    header('HTTP/1.1 415 Unsupported Media Type');
    echo 'Only images and videos are allowed';
    exit;
  }

  try {
    $fileMetadata = new Google\Service\Drive\DriveFile([
      'name' => $fileName,
      'parents' => [$originalFolderId],
    ]);

    $driveFileId = uploadFileToDrive($service, $client, $fileMetadata, $filePath, $fileMime);

    $db = getDb();
    $localKey = sha1($fileName . '|' . ($filePath ?: '') . '|' . microtime(true));
    $kind = str_starts_with($fileMime, 'video/') ? 'video' : 'image';
    $thumbPath = null;
    $displayPath = null;

    if ($kind === 'image') {
      $displayDir = __DIR__ . '/display';
      $thumbDir = __DIR__ . '/thumbs';
      if (!is_dir($displayDir)) {
        mkdir($displayDir, 0777, true);
      }
      if (!is_dir($thumbDir)) {
        mkdir($thumbDir, 0777, true);
      }

      $localBaseName = $localKey;
      $thumbPath = $thumbDir . '/' . $localBaseName . '.jpg';
      $displayPath = $displayDir . '/' . $localBaseName . '.jpg';

      generateImageThumbnail($filePath, $thumbPath);
      resizeImageForUpload($filePath, $displayPath, 1200);
    }

    $stmt = $db->prepare('
      INSERT OR REPLACE INTO uploads
        (local_key, file_name, mime_type, kind, drive_file_id, thumb_path, display_path, created_at)
      VALUES
        (:local_key, :file_name, :mime_type, :kind, :drive_file_id, :thumb_path, :display_path, :created_at)
    ');
    $stmt->execute([
      ':local_key' => $localKey,
      ':file_name' => $fileName,
      ':mime_type' => $fileMime,
      ':kind' => $kind,
      ':drive_file_id' => $driveFileId,
      ':thumb_path' => $thumbPath,
      ':display_path' => $displayPath,
      ':created_at' => gmdate('c'),
    ]);

    // Cleanup: Remove the temporary file from local storage
    if ($isChunked && file_exists($filePath)) {
      unlink($filePath);
    }
    header('Content-Type: text/plain');
    echo 'success';

  } catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo "Google Drive Error: " . $e->getMessage();
  }
  exit;
}
