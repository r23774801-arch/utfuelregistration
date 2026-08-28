<?php
declare(strict_types=1);

function local_upload_url(?string $filename): ?string
{
    if (!$filename) {
        return null;
    }

    $safeName = basename($filename);
    $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $safeName;
    if (!is_file($absolutePath)) {
        return null;
    }

    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/.');
    return ($basePath === '' ? '' : $basePath) . '/uploads/' . rawurlencode($safeName);
}
