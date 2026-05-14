<?php

function getReadDb(string $dbPath): ?PDO
{
  static $cachedDbs = [];
  if (isset($cachedDbs[$dbPath])) {
    return $cachedDbs[$dbPath];
  }

  if (!file_exists($dbPath)) {
    return $cachedDbs[$dbPath] = null;
  }

  $db = new PDO('sqlite:' . $dbPath);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

  return $cachedDbs[$dbPath] = $db;
}

function getUploadsColumnMap(string $dbPath): array
{
  static $cachedMaps = [];
  if (isset($cachedMaps[$dbPath])) {
    return $cachedMaps[$dbPath];
  }

  $db = getReadDb($dbPath);
  if (!$db) {
    return $cachedMaps[$dbPath] = [];
  }

  $columns = [];
  foreach ($db->query('PRAGMA table_info(uploads)') as $column) {
    $columns[$column['name']] = true;
  }

  return $cachedMaps[$dbPath] = $columns;
}
