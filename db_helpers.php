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

function migrateUploadsSchema(string $dbPath): array
{
  $db = new PDO('sqlite:' . $dbPath);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $result = [
    'created' => false,
    'renamedLegacy' => false,
    'addedColumns' => [],
  ];

  $tableExists = (bool) $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='uploads'")->fetchColumn();
  if (!$tableExists) {
    $db->exec('
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
    $result['created'] = true;
    return $result;
  }

  $columns = [];
  foreach ($db->query('PRAGMA table_info(uploads)') as $column) {
    $columns[$column['name']] = $column;
  }

  $legacyLayout = isset($columns['drive_file_id']) || !isset($columns['object_key']);
  if ($legacyLayout) {
    $db->exec('ALTER TABLE uploads RENAME TO uploads_legacy');
    $db->exec('
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
    $result['renamedLegacy'] = true;
    return $result;
  }

  foreach (['preview_data_uri', 'thumb_width', 'thumb_height'] as $columnName) {
    if (!isset($columns[$columnName])) {
      $columnType = in_array($columnName, ['thumb_width', 'thumb_height'], true) ? 'INTEGER' : 'TEXT';
      $db->exec('ALTER TABLE uploads ADD COLUMN ' . $columnName . ' ' . $columnType);
      $result['addedColumns'][] = $columnName;
    }
  }

  return $result;
}
