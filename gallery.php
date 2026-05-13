<?php

$pageToken = $_GET['pageToken'] ?? '0';
$pageSize = 24;
$offset = max(0, (int) $pageToken);

$dbPath = __DIR__ . '/media.sqlite';
if (!file_exists($dbPath)) {
  header('X-Next-Page-Token: ');
  exit;
}

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $db->prepare('
  SELECT local_key, file_name, mime_type, kind, object_key, preview_data_uri, thumb_object_key, created_at
  FROM uploads
  ORDER BY datetime(created_at) DESC
  LIMIT :limit OFFSET :offset
');
$stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($files as $row) {
  $id = htmlspecialchars($row['local_key'], ENT_QUOTES, 'UTF-8');
  $name = htmlspecialchars($row['file_name'], ENT_QUOTES, 'UTF-8');
  $kind = htmlspecialchars($row['kind'], ENT_QUOTES, 'UTF-8');
  $mimeType = htmlspecialchars($row['mime_type'], ENT_QUOTES, 'UTF-8');
  $src = htmlspecialchars('media.php?id=' . rawurlencode($row['local_key']) . '&variant=full', ENT_QUOTES, 'UTF-8');
  $displaySrc = htmlspecialchars('media.php?id=' . rawurlencode($row['local_key']) . '&variant=display', ENT_QUOTES, 'UTF-8');
  $thumbSrc = htmlspecialchars('media.php?id=' . rawurlencode($row['local_key']) . '&variant=thumb', ENT_QUOTES, 'UTF-8');
  $previewSrc = htmlspecialchars($row['preview_data_uri'] ?: ('media.php?id=' . rawurlencode($row['local_key']) . '&variant=thumb'), ENT_QUOTES, 'UTF-8');

  echo '<figure class="overflow-hidden">';
  echo '<button type="button" data-index="" data-id="' . $id . '" data-name="' . $name . '" data-kind="' . $kind . '" data-mime-type="' . $mimeType . '" data-src="' . $src . '" data-display-src="' . $displaySrc . '" data-thumb-src="' . $thumbSrc . '" class="block w-full text-left">';
  if ($row['kind'] === 'video') {
    echo '<div class="relative aspect-[1/1] w-full overflow-hidden bg-black">';
    echo '<div class="absolute inset-0 bg-[#120f0d]"></div>';
    echo '<div class="absolute inset-0 flex items-center justify-center text-white/90">';
    echo '<span class="rounded-full bg-black/55 px-3 py-1 text-xs font-medium tracking-wide backdrop-blur-sm">Video</span>';
    echo '</div></div>';
  } else {
    echo '<img src="' . $previewSrc . '" data-thumb-src="' . $thumbSrc . '" alt="' . $name . '" class="aspect-[1/1] w-full object-cover" loading="lazy" decoding="async" fetchpriority="low">';
  }
  echo '</button></figure>';
}

$countStmt = $db->query('SELECT COUNT(*) AS count FROM uploads');
$count = (int) ($countStmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0);
$nextPageToken = ($offset + $pageSize < $count) ? (string) ($offset + $pageSize) : '';
header('X-Next-Page-Token: ' . $nextPageToken);
