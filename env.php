<?php

function loadEnvFile(string $path): void
{
  if (!is_file($path) || !is_readable($path)) {
    return;
  }

  $lines = file($path, FILE_IGNORE_NEW_LINES);
  if ($lines === false) {
    return;
  }

  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
      continue;
    }

    $equalsPos = strpos($line, '=');
    if ($equalsPos === false) {
      continue;
    }

    $key = trim(substr($line, 0, $equalsPos));
    $value = trim(substr($line, $equalsPos + 1));
    if ($key === '') {
      continue;
    }

    if (
      (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
      (str_starts_with($value, "'") && str_ends_with($value, "'"))
    ) {
      $value = substr($value, 1, -1);
    }

    if (getenv($key) === false) {
      putenv($key . '=' . $value);
      $_ENV[$key] = $value;
      $_SERVER[$key] = $value;
    }
  }
}
