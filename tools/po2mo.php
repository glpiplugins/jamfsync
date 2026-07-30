<?php

declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/po2mo.php input.po output.mo\n");
    exit(1);
}

$input = $argv[1];
$output = $argv[2];

if (!is_file($input)) {
    fwrite(STDERR, "Input not found: {$input}\n");
    exit(1);
}

$lines = file($input, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "Cannot read: {$input}\n");
    exit(1);
}

$entries = [];
$current = [
    'msgid' => null,
    'msgid_plural' => null,
    'msgstr' => [],
];
$active = null;
$activeIndex = null;

$flush = static function () use (&$entries, &$current): void {
    if ($current['msgid'] === null) {
        return;
    }

    $id = (string) $current['msgid'];
    if ($current['msgid_plural'] !== null) {
        $id .= "\0" . (string) $current['msgid_plural'];
    }

    if ($current['msgstr'] === []) {
        $translation = '';
    } else {
        ksort($current['msgstr']);
        $translation = implode("\0", array_values($current['msgstr']));
    }

    $entries[$id] = $translation;

    $current = [
        'msgid' => null,
        'msgid_plural' => null,
        'msgstr' => [],
    ];
};

$decodePoString = static function (string $quoted): string {
    $quoted = trim($quoted);
    if ($quoted === '' || $quoted[0] !== '"') {
        return '';
    }

    return stripcslashes(substr($quoted, 1, -1));
};

foreach ($lines as $rawLine) {
    $line = trim($rawLine);

    if ($line === '') {
        $flush();
        $active = null;
        $activeIndex = null;
        continue;
    }

    if ($line[0] === '#') {
        continue;
    }

    if (str_starts_with($line, 'msgid_plural')) {
        $current['msgid_plural'] = $decodePoString(trim(substr($line, strlen('msgid_plural'))));
        $active = 'msgid_plural';
        $activeIndex = null;
        continue;
    }

    if (preg_match('/^msgstr\[(\d+)\]\s+(.+)$/', $line, $m)) {
        $idx = (int) $m[1];
        $current['msgstr'][$idx] = $decodePoString($m[2]);
        $active = 'msgstr';
        $activeIndex = $idx;
        continue;
    }

    if (str_starts_with($line, 'msgstr')) {
        $current['msgstr'][0] = $decodePoString(trim(substr($line, strlen('msgstr'))));
        $active = 'msgstr';
        $activeIndex = 0;
        continue;
    }

    if (str_starts_with($line, 'msgid')) {
        if ($current['msgid'] !== null) {
            $flush();
        }
        $current['msgid'] = $decodePoString(trim(substr($line, strlen('msgid'))));
        $active = 'msgid';
        $activeIndex = null;
        continue;
    }

    if ($line[0] === '"') {
        $chunk = $decodePoString($line);
        if ($active === 'msgid') {
            $current['msgid'] = (string) $current['msgid'] . $chunk;
        } elseif ($active === 'msgid_plural') {
            $current['msgid_plural'] = (string) $current['msgid_plural'] . $chunk;
        } elseif ($active === 'msgstr' && $activeIndex !== null) {
            $current['msgstr'][$activeIndex] = (string) ($current['msgstr'][$activeIndex] ?? '') . $chunk;
        }
    }
}
$flush();

ksort($entries, SORT_STRING);

$n = count($entries);
$headerSize = 28;
$origTableOffset = $headerSize;
$transTableOffset = $origTableOffset + ($n * 8);
$stringsOffset = $transTableOffset + ($n * 8);

$origTable = '';
$transTable = '';
$origData = '';
$transData = '';

$currentOrigOffset = $stringsOffset;
$currentTransOffset = $stringsOffset;

$currentTransOffset += array_sum(array_map(static fn($k) => strlen($k) + 1, array_keys($entries)));

foreach ($entries as $msgid => $msgstr) {
    $origLen = strlen($msgid);
    $transLen = strlen($msgstr);

    $origTable .= pack('VV', $origLen, $currentOrigOffset);
    $transTable .= pack('VV', $transLen, $currentTransOffset);

    $origData .= $msgid . "\0";
    $transData .= $msgstr . "\0";

    $currentOrigOffset += $origLen + 1;
    $currentTransOffset += $transLen + 1;
}

$mo = '';
$mo .= pack('V', 0x950412de);
$mo .= pack('V', 0);
$mo .= pack('V', $n);
$mo .= pack('V', $origTableOffset);
$mo .= pack('V', $transTableOffset);
$mo .= pack('V', 0);
$mo .= pack('V', $stringsOffset + strlen($origData) + strlen($transData));
$mo .= $origTable;
$mo .= $transTable;
$mo .= $origData;
$mo .= $transData;

if (file_put_contents($output, $mo) === false) {
    fwrite(STDERR, "Cannot write: {$output}\n");
    exit(1);
}

fwrite(STDOUT, "Generated {$output} ({$n} entries)\n");
