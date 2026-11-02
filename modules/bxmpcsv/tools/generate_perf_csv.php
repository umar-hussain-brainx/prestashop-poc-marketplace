<?php
/**
 * Generates a synthetic offer CSV for performance runs (pure PHP, no PrestaShop needed).
 *
 *   php modules/bxmpcsv/tools/generate_perf_csv.php --rows=4000 --out=/path/perf.csv [--prefix=PERF] [--matched=N]
 *
 * Rows get unique GS1-valid EANs (prefix 8009...) and distinct titles, so they create master products on a first
 * import and update the offers on the next one. --matched=N appends N extra rows re-using the existing seeded demo
 * EANs (matched immediately).
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
$o = getopt('', ['rows::', 'out:', 'prefix::', 'matched::']);
$n = max(1, (int) ($o['rows'] ?? 4000));
$prefix = preg_replace('/[^A-Z0-9-]/', '', strtoupper((string) ($o['prefix'] ?? 'PERF'))) ?: 'PERF';
$out = (string) ($o['out'] ?? 'php://stdout');
$ean = static function (string $first12): string {
    $s = 0;
    for ($i = 0; $i < 12; ++$i) {
        $s += (int) $first12[$i] * ($i % 2 === 0 ? 1 : 3);
    }

    return $first12 . ((10 - $s % 10) % 10);
};
$adj = ['Classic', 'Modern', 'Rustic', 'Compact', 'Deluxe', 'Eco', 'Urban', 'Vintage', 'Smart', 'Premium', 'Nordic', 'Coastal'];
$noun = ['kettle', 'lamp', 'chair', 'blanket', 'backpack', 'speaker', 'planter', 'clock', 'mirror', 'rug', 'jar', 'tray', 'vase', 'stool', 'bottle'];
$color = ['red', 'blue', 'green', 'black', 'white', 'oak', 'grey', 'sand', 'teal', 'amber'];
$brands = ['Nordic Home', 'Lumen & Co', 'Studio Design', 'Graphic Corner', 'Perf Brand'];
$cats = ['Home > Perf > Kitchen', 'Home > Perf > Living', 'Home > Perf > Outdoor', 'Home > Perf > Office'];
$h = fopen($out, 'wb');
fwrite($h, "\xEF\xBB\xBF");
fputcsv($h, ['vendor_sku', 'ean', 'reference', 'brand', 'title', 'description', 'category', 'price', 'discount_price', 'quantity', 'condition', 'active'], ';', '"', '');
for ($i = 1; $i <= $n; ++$i) {
    $title = sprintf('%s %s %s %s', $adj[$i % 12], $color[intdiv($i, 12) % 10], $noun[intdiv($i, 120) % 15], 'model ' . strtoupper(base_convert((string) ($i * 7919), 10, 36)));
    $price = 5 + ($i * 37 % 9500) / 100;
    fputcsv($h, [
        sprintf('%s-%05d', $prefix, $i), $ean('8009' . str_pad((string) $i, 8, '0', STR_PAD_LEFT)), sprintf('%s-MPN-%05d', $prefix, $i), $brands[$i % 5], $title,
        '<p>' . $title . ' — synthetic performance row.</p>', $cats[$i % 4], number_format($price, 2, ',', '.'), $i % 10 === 0 ? number_format($price * 0.9, 2, ',', '.') : '',
        (string) ($i % 50), 'new', '1',
    ], ';', '"', '');
}
$demo = ['8001001000015', '8001001000022', '8001002000090', '8001003000136', '8002000000068', '8002000000075', '8002000000082', '8001009000222', '8001010000242'];
for ($j = 0; $j < (int) ($o['matched'] ?? 0); ++$j) {
    fputcsv($h, [sprintf('%s-M-%05d', $prefix, $j), $demo[$j % count($demo)], '', '', 'matched row', '', '', '12,00', '', '5', 'new', '1'], ';', '"', '');
}
fclose($h);
if ($out !== 'php://stdout') {
    fwrite(STDERR, "{$n} rows written to {$out}\n");
}
