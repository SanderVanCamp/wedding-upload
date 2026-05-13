<?php
require_once 'vendor/autoload.php';

$local = $_GET['local'] ?? '';
$fileId = $_GET['id'] ?? '';
if (isset($_GET['drive']) && $_GET['drive'] === '1') {
  $driveMode = true;
} else {
  $driveMode = false;
}

if ($local === 'thumb' || $local === 'display') {
  if ($fileId === '') {
    header('HTTP/1.1 400 Bad Request');
    exit('Missing file id');
  }

  $baseDir = $local === 'thumb' ? (__DIR__ . '/thumbs') : (__DIR__ . '/display');
  $candidates = [
    $baseDir . '/' . $fileId . '.jpg',
    $baseDir . '/' . $fileId . '.jpeg',
    $baseDir . '/' . $fileId . '.png',
    $baseDir . '/' . $fileId . '.webp',
  ];

  foreach ($candidates as $path) {
    if (!file_exists($path)) {
      continue;
    }

    $contentType = mime_content_type($path) ?: 'application/octet-stream';
    header('Content-Type: ' . $contentType);
    header('Cache-Control: public, max-age=86400');
    readfile($path);
    exit;
  }

  header('HTTP/1.1 404 Not Found');
  exit('Local media not found');
}

$thumb = isset($_GET['thumb']) && $_GET['thumb'] === '1';
$thumbUrl = $_GET['thumbUrl'] ?? '';
if ($fileId === '') {
  header('HTTP/1.1 400 Bad Request');
  exit('Missing file id');
}

if ($thumb) {
  $thumbLink = $thumbUrl;
  if ($thumbLink === '') {
    $clientId = getenv('GOOGLE_CLIENT_ID');
    $clientSecret = getenv('GOOGLE_CLIENT_SECRET');
    $refreshToken = getenv('GOOGLE_REFRESH_TOKEN');

    $client = new Google\Client();
    $client->setClientId($clientId);
    $client->setClientSecret($clientSecret);
    $client->addScope(Google\Service\Drive::DRIVE_FILE);
    $client->fetchAccessTokenWithRefreshToken($refreshToken);

    $drive = new Google\Service\Drive($client);
    try {
      $file = $drive->files->get($fileId, [
        'fields' => 'thumbnailLink,mimeType',
        'supportsAllDrives' => true,
      ]);
    } catch (Exception $e) {
      header('HTTP/1.1 500 Internal Server Error');
      exit('Failed to load thumbnail metadata');
    }

    $thumbLink = $file->getThumbnailLink();
    if (!$thumbLink) {
      header('HTTP/1.1 404 Not Found');
      exit('No thumbnail available');
    }
  }

  $ch = curl_init($thumbLink);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
  ]);

  $body = curl_exec($ch);
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  curl_close($ch);

  if ($status < 200 || $status >= 300 || $body === false) {
    header('HTTP/1.1 500 Internal Server Error');
    exit('Failed to load thumbnail');
  }

  header('Content-Type: ' . ($contentType ?: 'image/jpeg'));
  header('Cache-Control: public, max-age=3600');
  echo $body;
  exit;
}

$clientId = getenv('GOOGLE_CLIENT_ID');
$clientSecret = getenv('GOOGLE_CLIENT_SECRET');
$refreshToken = getenv('GOOGLE_REFRESH_TOKEN');

$client = new Google\Client();
$client->setClientId($clientId);
$client->setClientSecret($clientSecret);
$client->addScope(Google\Service\Drive::DRIVE_FILE);
$client->fetchAccessTokenWithRefreshToken($refreshToken);

$accessToken = $client->getAccessToken()['access_token'] ?? null;
if (!$accessToken) {
  header('HTTP/1.1 500 Internal Server Error');
  exit('Unable to authenticate with Google Drive');
}

$url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?alt=media&supportsAllDrives=true';
$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTPHEADER => [
    'Authorization: Bearer ' . $accessToken,
  ],
]);

$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($status < 200 || $status >= 300 || $body === false) {
  header('HTTP/1.1 500 Internal Server Error');
  exit('Failed to load media');
}

header('Content-Type: ' . ($contentType ?: 'application/octet-stream'));
header('Cache-Control: public, max-age=86400');
echo $body;
