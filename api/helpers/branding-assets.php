<?php
declare(strict_types=1);

function branding_asset_data_root(): ?string
{
    $applicationRoot = realpath(__DIR__ . '/../../html');
    if ($applicationRoot === false) {
        return null;
    }

    $dataRoot = realpath($applicationRoot . DIRECTORY_SEPARATOR . 'data');

    return $dataRoot !== false ? $dataRoot : null;
}

function branding_asset_public_url(string $rawPath, string $tenantId, string $assetType): string
{
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $tenantId) !== 1) {
        return '';
    }

    $config = match ($assetType) {
        'logo' => [
            'directory' => 'logo',
            'endpoint' => '/api/system/logo-front.php',
            'allowed_files' => [
                'logo-front.png',
                'logo-front.jpg',
                'logo-front.jpeg',
                'logo-front.webp',
            ],
        ],
        'favicon' => [
            'directory' => 'favicon',
            'endpoint' => '/api/system/favicon-front.php',
            'allowed_files' => ['favicon-front.png'],
        ],
        default => null,
    };

    if (!is_array($config)) {
        return '';
    }

    $rawPath = trim($rawPath);
    $path = $rawPath !== '' ? parse_url($rawPath, PHP_URL_PATH) : null;
    $expectedPrefix = '/data/' . $config['directory'] . '/' . $tenantId . '/';

    if (!is_string($path) || !str_starts_with($path, $expectedPrefix)) {
        return '';
    }

    $fileName = basename($path);
    if ($path !== $expectedPrefix . $fileName
        || !in_array($fileName, $config['allowed_files'], true)) {
        return '';
    }

    $dataRootPath = branding_asset_data_root();
    if ($dataRootPath === null) {
        return '';
    }

    $storageRootPath = realpath($dataRootPath . DIRECTORY_SEPARATOR . $config['directory']);
    $tenantBasePath = $storageRootPath !== false
        ? realpath($storageRootPath . DIRECTORY_SEPARATOR . $tenantId)
        : false;
    $filePath = $tenantBasePath !== false
        ? realpath($tenantBasePath . DIRECTORY_SEPARATOR . $fileName)
        : false;

    if ($storageRootPath === false
        || $storageRootPath !== $dataRootPath . DIRECTORY_SEPARATOR . $config['directory']
        || $tenantBasePath === false
        || $tenantBasePath !== $storageRootPath . DIRECTORY_SEPARATOR . $tenantId
        || $filePath === false
        || !str_starts_with($filePath, $tenantBasePath . DIRECTORY_SEPARATOR)
        || !is_file($filePath)
        || !is_readable($filePath)) {
        return '';
    }

    clearstatcache(true, $filePath);
    $modifiedAt = @filemtime($filePath);
    $version = $modifiedAt !== false && $modifiedAt > 0
        ? (string) $modifiedAt
        : substr(hash('sha256', $assetType . '|' . $rawPath), 0, 16);

    return $config['endpoint'] . '?v=' . rawurlencode($version);
}
