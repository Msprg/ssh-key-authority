#!/usr/bin/env php
<?php

declare(strict_types=1);

$options = getopt('', ['html:', 'comment:']);
$htmlPath = $options['html'] ?? null;
$comment = $options['comment'] ?? null;

if ($htmlPath === null || $comment === null) {
    fwrite(STDERR, "Usage: find_user_public_key.php --html=<path> --comment=<comment>\n");
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
$buttons = $xpath->query('//button[@name="delete_public_key"]');
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
    $cells = $xpath->query('.//td', $row);
    if ($cells === false) {
        continue;
    }
    foreach ($cells as $cell) {
        $text = trim((string)$cell->textContent);
        if ($text === $comment) {
            $id = trim((string)$button->getAttribute('value'));
            if ($id !== '') {
                echo $id;
                exit(0);
            }
        }
    }
}

exit(1);
