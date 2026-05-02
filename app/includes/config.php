<?php

function elevaro_config_path(string $file): string
{
    // Live server: /var/www/vhosts/elevaro.app/config
    $externalConfig = dirname(__DIR__, 2) . '/config/' . $file . '.php';

    if (file_exists($externalConfig)) {
        return $externalConfig;
    }

    // Local fallback for development examples.
    $exampleConfig = dirname(__DIR__, 2) . '/config.example/' . $file . '.php';

    if (file_exists($exampleConfig)) {
        return $exampleConfig;
    }

    throw new RuntimeException("Config file not found: {$file}.php");
}

function elevaro_config(string $file): array
{
    $config = require elevaro_config_path($file);

    if (!is_array($config)) {
        throw new RuntimeException("Config file {$file}.php must return an array.");
    }

    return $config;
}
