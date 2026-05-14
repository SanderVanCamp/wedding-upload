<?php

function getDisplayObjectKey(string $originalKey): string
{
  return str_replace('/originals/', '/display/', $originalKey);
}

function getThumbObjectKey(array $row): string
{
  $displayObjectKey = getDisplayObjectKey((string) ($row['object_key'] ?? ''));
  $thumbObjectKey = trim((string) ($row['thumb_object_key'] ?? ''));
  return $thumbObjectKey !== '' ? $thumbObjectKey : $displayObjectKey;
}
