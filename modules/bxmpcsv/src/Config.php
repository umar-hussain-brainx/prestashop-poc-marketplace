<?php

declare(strict_types=1);

namespace BrainX\Csv;

/**
 * Module settings (PS Configuration) and storage locations.
 */
final class Config
{
    public const DEFAULTS = [
        'BXMPCSV_AUTO_CREATE' => 1,          // create a master product when no match at all
        'BXMPCSV_FULL_SYNC' => 0,            // offers missing from a (full-feed) import → quantity 0
        'BXMPCSV_CHUNK_SIZE' => 500,         // rows per chunk (transaction + progress persistence)
        'BXMPCSV_MAX_RUNTIME' => 50,         // seconds per cron/worker invocation (0 = unlimited for CLI)
        'BXMPCSV_IMPORT_IMAGES' => 1,        // download images of newly created master products
        'BXMPCSV_ID_CATEGORY' => 0,          // default category for new products (0 = Home)
        'BXMPCSV_CREATE_CATEGORIES' => 1,    // create missing categories of a "A > B" path
        'BXMPCSV_TAX_RULES_GROUP' => 0,      // tax rules group of created products
        'BXMPCSV_PRODUCT_ACTIVE' => 1,       // created master products are active
        'BXMPCSV_TITLE_THRESHOLD' => '0.6',  // MatchingEngine title similarity threshold
        'BXMPCSV_EAN_DECIDES_NEW' => 1,      // title-only candidates that carry another EAN do not block creation
        'BXMPCSV_SYNC_STOCK' => 1,           // keep PS StockAvailable = sum of active vendor offers
        'BXMPCSV_ALLOWED_PATHS' => '',       // extra server directories allowed as "path" sources (comma separated)
        'BXMPCSV_CRON_TOKEN' => '',          // generated at install
    ];

    public static function get(string $key): mixed
    {
        $v = \Configuration::get($key);

        return $v === false || $v === null ? (self::DEFAULTS[$key] ?? null) : $v;
    }

    public static function bool(string $key): bool
    {
        return (bool) (int) self::get($key);
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    public static function float(string $key): float
    {
        return (float) str_replace(',', '.', (string) self::get($key));
    }

    public static function install(): bool
    {
        foreach (self::DEFAULTS as $k => $v) {
            if (\Configuration::get($k) === false) {
                \Configuration::updateValue($k, $v);
            }
        }
        if ((string) \Configuration::get('BXMPCSV_CRON_TOKEN') === '') {
            \Configuration::updateValue('BXMPCSV_CRON_TOKEN', bin2hex(random_bytes(16)));
        }

        return true;
    }

    public static function uninstall(): bool
    {
        foreach (array_keys(self::DEFAULTS) as $k) {
            \Configuration::deleteByName($k);
        }

        return true;
    }

    public static function cronToken(): string
    {
        return (string) self::get('BXMPCSV_CRON_TOKEN');
    }

    public static function cronUrl(): string
    {
        return \Context::getContext()->link->getModuleLink('bxmpcsv', 'cron', ['token' => self::cronToken()], null, null, null, true);
    }

    /** Private storage for uploaded / downloaded files, job metadata and error reports (not web accessible). */
    public static function storageDir(): string
    {
        $dir = _PS_ROOT_DIR_ . '/var/bxmp_csv';
        self::ensureDir($dir);

        return $dir;
    }

    public static function jobsDir(): string
    {
        $dir = self::storageDir() . '/jobs';
        self::ensureDir($dir);

        return $dir;
    }

    /** Server directory for scheduled feed files (docker: host ./var/feeds). */
    public static function feedsDir(): string
    {
        return _PS_ROOT_DIR_ . '/var/bxmp_feeds';
    }

    /** @return string[] real paths of directories a "path" source may read from */
    public static function allowedRoots(): array
    {
        $roots = [self::feedsDir(), self::storageDir()];
        foreach (explode(',', (string) self::get('BXMPCSV_ALLOWED_PATHS')) as $p) {
            if (trim($p) !== '') {
                $roots[] = trim($p);
            }
        }

        return array_values(array_filter(array_map(static fn ($r) => realpath($r) ?: null, $roots)));
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (is_dir($dir) && !is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\n");
            @file_put_contents($dir . '/index.php', "<?php\nheader('Location: ../');\nexit;\n");
        }
    }
}
