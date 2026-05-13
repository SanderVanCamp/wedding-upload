<?php
require_once 'vendor/autoload.php';

$fileId = $_GET['id'] ?? '';
if ($fileId === '') {
  header('HTTP/1.1 400 Bad Request');
  exit('Missing file id');
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
  exit('Failed to load image');
}

header('Content-Type: ' . ($contentType ?: 'application/octet-stream'));
echo $body;
