#!/usr/bin/env php
<?php

declare(strict_types=1);

$options = getopt('', ['html:', 'source-user:']);
$htmlPath = $options['html'] ?? null;
$sourceUser = $options['source-user'] ?? null;

if ($htmlPath === null || $sourceUser === null) {
    fwrite(STDERR, "Usage: find_account_access_rule.php --html=<path> --source-user=<uid>\n");
    exit(2);
}

if (!is_file($htmlPath)) {
    fwrite(STDERR, "HTML file not found: {$htmlPath}\n");
    exit(2);
}

$html = file_get_contents($htmlPath);
if ($html === false) {
    fwrite(STDERR, "Failed to read HTML file: {$htmlPath}\n");
    exit(2);
}

$doc = new DOMDocument();
libxml_use_internal_errors(true);
$loaded = $doc->loadHTML($html);
libxml_clear_errors();
if (!$loaded) {
    fwrite(STDERR, "Failed to parse HTML file: {$htmlPath}\n");
    exit(2);
}

$xpath = new DOMXPath($doc);
$buttons = $xpath->query('//button[@name="delete_access"]');
if ($buttons === false) {
    exit(1);
}

foreach ($buttons as $button) {
    $row = $button;
    while ($row !== null && $row->nodeName !== 'tr') {
        $row = $row->parentNode;
    }
    if ($row === null) {
        continue;
    }
    $userLinks = $xpath->query('.//a[contains(concat(" ", normalize-space(@class), " "), " user ")]', $row);
    if ($userLinks === false) {
        continue;
    }
    foreach ($userLinks as $link) {
        $text = trim((string)$link->textContent);
        if ($text === $sourceUser) {
            $id = trim((string)$button->getAttribute('value'));
            if ($id !== '') {
                echo $id;
                exit(0);
            }
        }
    }
}

exit(1);
