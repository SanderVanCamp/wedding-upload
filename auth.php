<?php
require_once 'vendor/autoload.php';

// These come from your .ddev/.env file
$clientId     = getenv('GOOGLE_CLIENT_ID');
$clientSecret = getenv('GOOGLE_CLIENT_SECRET');
// This MUST match exactly what you put in the Google Console
$redirectUri  = 'https://' . getenv('DDEV_HOSTNAME') . '/auth.php';

$client = new Google\Client();
$client->setClientId($clientId);
$client->setClientSecret($clientSecret);
$client->setRedirectUri($redirectUri);
$client->addScope('https://www.googleapis.com/auth/photoslibrary.appendonly');
$client->addScope('https://www.googleapis.com/auth/photoslibrary.readonly.appcreateddata');
$client->setAccessType('offline');
$client->setPrompt('consent');

if (!isset($_GET['code'])) {
  // Step 1: Send you to Google to click "Allow"
  $authUrl = $client->createAuthUrl();
  header('Location: ' . $authUrl);
  exit;
} else {
  // Step 2: Google sent you back to DDEV with a ?code=...
  $accessToken = $client->fetchAccessTokenWithAuthCode($_GET['code']);

  if (isset($accessToken['refresh_token'])) {
    echo "<h1>Success!</h1>";
    echo "<p>Copy this Refresh Token into your .env and Caddyfile:</p>";
    echo "<code style='background:#eee;padding:10px;display:block;'>" . $accessToken['refresh_token'] . "</code>";
  } else {
    echo "<h1>Error</h1>";
    echo "<p>No refresh token was returned. Did you already authorize this? Try removing the app from your Google Account settings and trying again.</p>";
  }
  exit;
}
