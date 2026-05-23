<?php

require_once __DIR__ . '/request_guard.php';
guardRequest(['GET', 'HEAD', 'POST'], true);
applySecurityHeaders();
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/env.php';
loadEnvFile('/var/www/wedding-upload/.env');

use Aws\S3\S3Client;

const RESET_CONFIRMATION = 'RESET EVERYTHING';

function getAdminS3Client(): S3Client
{
  $region = getenv('HETZNER_S3_REGION') ?: 'us-east-1';
  $endpoint = getenv('HETZNER_S3_ENDPOINT');
  if ($endpoint === false || $endpoint === '') {
    throw new RuntimeException('Missing HETZNER_S3_ENDPOINT');
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

function getAdminBucketName(): string
{
  $bucket = getenv('HETZNER_S3_BUCKET');
  if ($bucket === false || $bucket === '') {
    throw new RuntimeException('Missing HETZNER_S3_BUCKET');
  }
  return $bucket;
}

function emptyBucket(S3Client $s3, string $bucket): int
{
  $deletedCount = 0;
  $continuationToken = null;

  do {
    $listArgs = ['Bucket' => $bucket, 'MaxKeys' => 1000];
    if (is_string($continuationToken) && $continuationToken !== '') {
      $listArgs['ContinuationToken'] = $continuationToken;
    }

    $result = $s3->listObjectsV2($listArgs);
    $contents = $result['Contents'] ?? [];
    if ($contents) {
      $objects = [];
      foreach ($contents as $object) {
        $key = (string) ($object['Key'] ?? '');
        if ($key !== '') {
          $objects[] = ['Key' => $key];
        }
      }

      if ($objects) {
        $deleteResult = $s3->deleteObjects([
          'Bucket' => $bucket,
          'Delete' => [
            'Objects' => $objects,
            'Quiet' => true,
          ],
        ]);

        $errors = $deleteResult['Errors'] ?? [];
        if ($errors) {
          $firstError = $errors[0];
          $message = (string) ($firstError['Message'] ?? 'Unknown S3 delete error');
          throw new RuntimeException('S3 delete failed: ' . $message);
        }

        $deletedCount += count($objects);
      }
    }

    $continuationToken = (string) ($result['NextContinuationToken'] ?? '');
  } while (!empty($result['IsTruncated']));

  return $deletedCount;
}

function clearSqliteDatabase(): bool
{
  $removedAny = false;
  foreach (['media.sqlite', 'media.sqlite-shm', 'media.sqlite-wal'] as $fileName) {
    $path = __DIR__ . '/' . $fileName;
    if (is_file($path)) {
      if (!unlink($path)) {
        throw new RuntimeException('Could not remove ' . $fileName);
      }
      $removedAny = true;
    }
  }

  return $removedAny;
}

function h(string $value): string
{
  return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$status = null;
$error = null;
$adminPassword = (string) (getenv('ADMIN_RESET_PASSWORD') ?: '');
$bucketName = (string) (getenv('HETZNER_S3_BUCKET') ?: '');
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$submittedToken = (string) ($_POST['token'] ?? ($_GET['token'] ?? ''));
$tokenIsValid = $adminPassword !== '' && $submittedToken !== '' && hash_equals($adminPassword, $submittedToken);

if ($requestMethod === 'POST') {
  try {
    if ($adminPassword === '') {
      throw new RuntimeException('ADMIN_RESET_PASSWORD is not configured.');
    }

    $confirmation = (string) ($_POST['confirmation'] ?? '');
    if (!$tokenIsValid) {
      throw new RuntimeException('Invalid admin token.');
    }
    if ($confirmation !== RESET_CONFIRMATION) {
      throw new RuntimeException('Confirmation phrase did not match.');
    }

    $deletedObjects = emptyBucket(getAdminS3Client(), getAdminBucketName());
    $databaseRemoved = clearSqliteDatabase();
    $status = [
      'deletedObjects' => $deletedObjects,
      'databaseRemoved' => $databaseRemoved,
    ];
  } catch (Throwable $exception) {
    $error = $exception->getMessage();
  }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, notranslate">
  <title>Admin reset</title>
  <link href="/dist/output.css?v=7" rel="stylesheet">
</head>
<body class="min-h-screen bg-[#f4f1ed] px-4 py-8 font-body text-[#1f1a17] sm:px-6 lg:px-8">
  <main class="mx-auto max-w-5xl">
    <header class="flex flex-col gap-5 border-b border-[#ded6cd] pb-6 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="mb-3 inline-flex items-center gap-2 rounded-full border border-[#d8cec3] bg-white px-3.5 py-1.5 text-xs font-semibold uppercase tracking-wide text-[#6b625b] shadow-sm">
          <span class="h-2.5 w-2.5 rounded-full <?php echo $tokenIsValid ? 'bg-emerald-500' : 'bg-amber-500'; ?>"></span>
          Admin console
        </div>
        <h1 class="text-3xl font-semibold tracking-normal text-[#1f1a17] sm:text-4xl">Storage operations</h1>
        <p class="mt-2 max-w-2xl text-sm leading-6 text-[#6b625b]">
          Manage the wedding upload archive and reset all uploaded media when needed.
        </p>
      </div>
      <div class="rounded-2xl border border-[#ded6cd] bg-white px-4 py-3 text-sm text-[#4e4640] shadow-sm">
        <span class="block text-xs font-semibold uppercase tracking-wide text-[#8a8178]">Bucket</span>
        <span class="mt-1 block font-mono text-xs sm:text-sm"><?php echo $tokenIsValid ? ($bucketName !== '' ? h($bucketName) : 'Not configured') : 'Locked'; ?></span>
      </div>
    </header>

    <?php if ($adminPassword === ''): ?>
      <div class="mt-6 rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950 shadow-sm">
        <strong class="font-semibold">Configuration required.</strong>
        Set <code class="rounded-lg bg-amber-100 px-1.5 py-0.5">ADMIN_RESET_PASSWORD</code> before using admin actions.
      </div>
    <?php endif; ?>

    <?php if ($error !== null): ?>
      <div class="mt-6 rounded-2xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-950 shadow-sm">
        <strong class="font-semibold">Action failed.</strong>
        <?php echo h($error); ?>
      </div>
    <?php endif; ?>

    <?php if ($status !== null): ?>
      <div class="mt-6 rounded-2xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-950 shadow-sm">
        <strong class="font-semibold">Reset complete.</strong>
        Deleted <?php echo (int) $status['deletedObjects']; ?> S3 object<?php echo $status['deletedObjects'] === 1 ? '' : 's'; ?>.
        SQLite database <?php echo $status['databaseRemoved'] ? 'was removed' : 'did not exist'; ?>.
      </div>
    <?php endif; ?>

    <?php if (!$tokenIsValid): ?>
      <section class="mt-6 max-w-xl rounded-3xl border border-[#ded6cd] bg-white p-6 shadow-[0_18px_48px_rgba(31,26,23,0.06)]">
        <h2 class="text-lg font-semibold">Token required</h2>
        <p class="mt-1 text-sm leading-6 text-[#6b625b]">
          Open this page with the admin token in the URL, or enter it here to continue.
        </p>
        <form method="get" class="mt-5 grid gap-4">
          <label class="grid gap-2 text-sm font-medium text-[#3d362f]">
            Admin token
            <input type="password" name="token" autocomplete="current-password" required
                   class="min-h-12 rounded-2xl border border-[#cfc6bc] bg-white px-4 text-base outline-none transition focus:border-[#3e684d] focus:ring-4 focus:ring-[#3e684d]/15">
          </label>
          <button type="submit"
                  class="inline-flex min-h-12 items-center justify-center rounded-2xl bg-[#315f44] px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-[#244b35] disabled:cursor-not-allowed disabled:bg-[#b9c9bf]"
                  <?php echo $adminPassword === '' ? 'disabled' : ''; ?>>
            Continue
          </button>
        </form>
      </section>
    <?php else: ?>
    <section class="mt-6 grid gap-5 lg:grid-cols-[1fr_1fr]">
      <form method="get" action="/download-originals.php" class="rounded-3xl border border-[#ded6cd] bg-white p-6 shadow-[0_18px_48px_rgba(31,26,23,0.06)]">
        <input type="hidden" name="token" value="<?php echo h($submittedToken); ?>">
        <div class="flex items-start justify-between gap-4">
          <div>
            <h2 class="text-lg font-semibold">Backup originals</h2>
            <p class="mt-1 text-sm leading-6 text-[#6b625b]">Stream every object in <code class="rounded-lg bg-[#f4f1ed] px-1.5 py-0.5">uploads/originals/</code> as a TAR archive.</p>
          </div>
          <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-[#e5efe8] text-[#3e684d]">
            <svg viewBox="0 0 24 24" aria-hidden="true" class="h-5 w-5 fill-none stroke-current stroke-2">
              <path d="M12 3v12"></path>
              <path d="m7 10 5 5 5-5"></path>
              <path d="M5 21h14"></path>
            </svg>
          </div>
        </div>

        <div class="mt-5 grid gap-4">
          <button type="submit"
                  class="inline-flex min-h-12 items-center justify-center gap-2 rounded-2xl bg-[#315f44] px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-[#244b35] disabled:cursor-not-allowed disabled:bg-[#b9c9bf]"
                  <?php echo $adminPassword === '' ? 'disabled' : ''; ?>>
            <svg viewBox="0 0 24 24" aria-hidden="true" class="h-4 w-4 fill-none stroke-current stroke-2">
              <path d="M12 3v12"></path>
              <path d="m7 10 5 5 5-5"></path>
              <path d="M5 21h14"></path>
            </svg>
            Download TAR
          </button>
        </div>
      </form>

      <form method="post" class="rounded-3xl border border-red-200 bg-white p-6 shadow-[0_18px_48px_rgba(31,26,23,0.06)]">
        <input type="hidden" name="token" value="<?php echo h($submittedToken); ?>">
        <div class="flex items-start justify-between gap-4">
          <div>
            <h2 class="text-lg font-semibold text-red-950">Danger zone</h2>
            <p class="mt-1 text-sm leading-6 text-[#6b625b]">Deletes all S3 objects first, then removes the SQLite database files.</p>
          </div>
          <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-red-50 text-red-700">
            <svg viewBox="0 0 24 24" aria-hidden="true" class="h-5 w-5 fill-none stroke-current stroke-2">
              <path d="M10 11v6"></path>
              <path d="M14 11v6"></path>
              <path d="M4 7h16"></path>
              <path d="M6 7l1 14h10l1-14"></path>
              <path d="M9 7V4h6v3"></path>
            </svg>
          </div>
        </div>

        <div class="mt-5 grid gap-4">
          <label class="grid gap-2 text-sm font-medium text-[#3d362f]">
            <span>Confirmation phrase</span>
            <input type="text" name="confirmation" autocomplete="off" required placeholder="<?php echo h(RESET_CONFIRMATION); ?>"
                   class="min-h-12 rounded-2xl border border-[#cfc6bc] bg-white px-4 text-base outline-none transition focus:border-red-700 focus:ring-4 focus:ring-red-700/15">
          </label>

          <button type="submit"
                  class="inline-flex min-h-12 items-center justify-center gap-2 rounded-2xl bg-red-700 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-red-800 disabled:cursor-not-allowed disabled:bg-red-300"
                  <?php echo $adminPassword === '' ? 'disabled' : ''; ?>>
            <svg viewBox="0 0 24 24" aria-hidden="true" class="h-4 w-4 fill-none stroke-current stroke-2">
              <path d="M10 11v6"></path>
              <path d="M14 11v6"></path>
              <path d="M4 7h16"></path>
              <path d="M6 7l1 14h10l1-14"></path>
              <path d="M9 7V4h6v3"></path>
            </svg>
            Reset everything
          </button>
        </div>
      </form>
    </section>
    <?php endif; ?>
  </main>
</body>
</html>
