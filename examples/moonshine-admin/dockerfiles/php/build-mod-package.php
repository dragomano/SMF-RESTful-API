<?php

declare(strict_types=1);

// Builds the SMF Package Manager archive of the RESTful API mod.
// Usage: php build-package.php <source-dir> <target.zip>
//
// The build is deterministic: every entry gets a fixed timestamp, so
// rebuilding from the same src/ tree yields byte-identical archives and the
// sha256 recorded in the database seed stays valid.

const FIXED_MTIME = 946684800; // 2000-01-01 UTC

$source = $argv[1] ?? '';
$target = $argv[2] ?? '';

if (! is_dir($source) || ! is_file($source . '/package-info.xml')) {
    throw new RuntimeException('Pass the mod source directory (with package-info.xml) and the target zip path');
}

@unlink($target);

$zip = new ZipArchive;

if ($zip->open($target, ZipArchive::CREATE) !== true) {
    throw new RuntimeException('Cannot create ' . $target);
}

$added = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST,
);

// Collect and sort by relative path: filesystem enumeration order varies
// between machines/runs, and the entry order defines the archive bytes.
$entries = [];

foreach ($iterator as $item) {
    /** @var SplFileInfo $item */
    $entries[] = [
        'relative' => str_replace('\\', '/', substr($item->getPathname(), strlen($source) + 1)),
        'pathname' => $item->getPathname(),
        'is_dir'   => $item->isDir(),
    ];
}

usort($entries, static fn (array $a, array $b): int => strcmp($a['relative'], $b['relative']));

// Directory entries are skipped: libzip stamps them with the build time
// (setMtimeName is ignored for them) and unzip creates the folders from the
// file paths anyway. Files carry the fixed timestamp, so two builds from the
// same src/ tree are byte-identical.
foreach ($entries as $entry) {
    if ($entry['is_dir']) {
        continue;
    }

    // Keep the archive identical to the mod's src/ tree: package-info.xml
    // plus the Sources/ and Themes/ folders the Package Manager unpacks.
    $zip->addFile($entry['pathname'], $entry['relative']);
    $zip->setMtimeName($entry['relative'], FIXED_MTIME);
    ++$added;
}
$zip->close();

printf(
    "Packed %d files into %s (%d bytes, sha256 %s)%s",
    $added,
    $target,
    filesize($target),
    hash_file('sha256', $target),
    PHP_EOL,
);
