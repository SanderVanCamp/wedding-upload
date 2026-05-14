<?php

function respondWithStatus(int $statusCode, string $message): void
{
  http_response_code($statusCode);
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES);
  exit;
}

function loadAllowedHosts(): array
{
  static $cachedHosts = null;
  if (is_array($cachedHosts)) {
    return $cachedHosts;
  }

  $raw = getenv('APP_ALLOWED_HOSTS');
  if ($raw === false || trim($raw) === '') {
    $fallbackHost = $_SERVER['HTTP_HOST'] ?? '';
    $cachedHosts = $fallbackHost !== '' ? [$fallbackHost] : [];
    return $cachedHosts;
  }

  $cachedHosts = array_values(array_filter(array_map(static fn ($host) => strtolower(trim($host)), explode(',', $raw))));
  return $cachedHosts;
}

function normalizeHost(string $host): string
{
  $host = strtolower(trim($host));
  return preg_replace('/:\d+$/', '', $host) ?: $host;
}

function isAllowedHost(string $host, array $allowedHosts): bool
{
  $normalizedHost = normalizeHost($host);
  foreach ($allowedHosts as $allowedHost) {
    if ($normalizedHost === normalizeHost($allowedHost)) {
      return true;
    }
  }
  return false;
}

function guardRequest(array $allowedMethods, bool $checkOrigin = false): void
{
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  if (!in_array($method, $allowedMethods, true)) {
    header('Allow: ' . implode(', ', $allowedMethods));
    respondWithStatus(405, 'Method not allowed');
  }

  $allowedHosts = loadAllowedHosts();
  $host = normalizeHost((string) ($_SERVER['HTTP_HOST'] ?? ''));
  if ($allowedHosts && !isAllowedHost($host, $allowedHosts)) {
    respondWithStatus(403, 'Host not allowed');
  }

  if ($checkOrigin && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $originHost = $origin !== '' ? (parse_url($origin, PHP_URL_HOST) ?: '') : '';
    $refererHost = $referer !== '' ? (parse_url($referer, PHP_URL_HOST) ?: '') : '';

    if ($originHost !== '' && !isAllowedHost($originHost, $allowedHosts)) {
      respondWithStatus(403, 'Origin not allowed');
    }

    if ($originHost === '' && $refererHost !== '' && !isAllowedHost($refererHost, $allowedHosts)) {
      respondWithStatus(403, 'Referer not allowed');
    }
  }
}

function requireJsonResponse(): void
{
  header('Content-Type: application/json; charset=UTF-8');
  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: same-origin');
}

function applySecurityHeaders(): void
{
  static $headersApplied = false;
  if ($headersApplied) {
    return;
  }
  $headersApplied = true;

  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: same-origin');
  header('X-Frame-Options: DENY');
  header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; media-src 'self' https:; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; connect-src 'self' https:; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
}
