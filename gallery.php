<?php
header('Content-Type: application/json');

$pageToken = $_GET['pageToken'] ?? '0';
$pageSize = 24;
$offset = max(0, (int) $pageToken);

$dbPath = __DIR__ . '/media.sqlite';
if (!file_exists($dbPath)) {
  echo json_encode(['files' => [], 'nextPageToken' => null]);
  exit;
}

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $db->prepare('
  SELECT local_key, file_name, mime_type, kind, object_key, thumb_object_key, created_at
  FROM uploads
  ORDER BY datetime(created_at) DESC
  LIMIT :limit OFFSET :offset
');
$stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$files = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $files[] = [
      'id' => $row['local_key'],
      'name' => $row['file_name'],
      'mimeType' => $row['mime_type'],
      'kind' => $row['kind'],
      'createdTime' => $row['created_at'],
      'objectKey' => $row['object_key'],
      'displayObjectKey' => 'uploads/display/' . $row['local_key'] . '.jpg',
      'thumbObjectKey' => $row['thumb_object_key'] ?? null,
      'src' => 'media.php?id=' . rawurlencode($row['local_key']) . '&variant=full',
      'displaySrc' => 'media.php?id=' . rawurlencode($row['local_key']) . '&variant=display',
      'thumbSrc' => 'media.php?id=' . rawurlencode($row['local_key']) . '&variant=thumb',
    ];
  }

$countStmt = $db->query('SELECT COUNT(*) AS count FROM uploads');
$count = (int) ($countStmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0);

echo json_encode([
  'files' => $files,
  'nextPageToken' => ($offset + $pageSize < $count) ? (string) ($offset + $pageSize) : null,
]);
