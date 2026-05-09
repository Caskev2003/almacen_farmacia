<?php

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header("Location: $url");
    exit;
}

function basePath(string $path = ''): string
{
    $base = '/almacen-farmacia/public';
    return $base . ($path ? '/' . ltrim($path, '/') : '');
}