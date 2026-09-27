<?php
declare(strict_types=1);

$parts = glob(__DIR__ . '/ferry_app.sql.gz.part-*');
sort($parts, SORT_STRING);
if (count($parts) !== 88) {
    fwrite(STDERR, "Expected 88 database parts.\n");
    exit(1);
}

$outputPath = __DIR__ . '/ferry_app.sql.gz';
$output = fopen($outputPath, 'wb');
if ($output === false) {
    fwrite(STDERR, "Unable to create the database archive.\n");
    exit(1);
}

$hash = hash_init('sha256');
foreach ($parts as $part) {
    $input = fopen($part, 'rb');
    if ($input === false) {
        fclose($output);
        unlink($outputPath);
        fwrite(STDERR, "Unable to read a database part.\n");
        exit(1);
    }
    while (!feof($input)) {
        $chunk = fread($input, 1024 * 1024);
        if ($chunk === false) {
            fclose($input);
            fclose($output);
            unlink($outputPath);
            fwrite(STDERR, "Unable to read a database part.\n");
            exit(1);
        }
        hash_update($hash, $chunk);
        if (fwrite($output, $chunk) !== strlen($chunk)) {
            fclose($input);
            fclose($output);
            unlink($outputPath);
            fwrite(STDERR, "Unable to write the database archive.\n");
            exit(1);
        }
    }
    fclose($input);
}
fclose($output);

$actual = hash_final($hash);
$expected = '4fc110e6759d6b784e55b3a43252026677f4412fea791c3c84c34756ab5fd864';
if (!hash_equals($expected, $actual)) {
    unlink($outputPath);
    fwrite(STDERR, "Database checksum mismatch.\n");
    exit(1);
}
echo "Created $outputPath (SHA-256: $actual)\n";
