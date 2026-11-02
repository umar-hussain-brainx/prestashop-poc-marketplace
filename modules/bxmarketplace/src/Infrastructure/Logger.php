<?php

declare(strict_types=1);

namespace BrainX\Marketplace\Infrastructure;

/**
 * Marketplace logger writing to `bxmp_log` (channel, level, message, JSON context).
 *
 *   Logger::channel('api')->info('Offer saved', ['id_offer' => 3]);
 *   Logger::write('csv', Logger::ERROR, 'Row rejected', [...]);
 *
 * Never throws: logging failures fall back to PHP error_log.
 */
final class Logger
{
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    public function __construct(private readonly string $channel = 'core')
    {
    }

    public static function channel(string $channel): self
    {
        return new self($channel);
    }

    public function debug(string $message, array $context = []): void
    {
        self::write($this->channel, self::DEBUG, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        self::write($this->channel, self::INFO, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        self::write($this->channel, self::WARNING, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        self::write($this->channel, self::ERROR, $message, $context);
    }

    public static function write(string $channel, string $level, string $message, array $context = []): void
    {
        try {
            \Db::getInstance()->insert('bxmp_log', [
                'channel' => pSQL(substr($channel, 0, 32)),
                'level' => pSQL(substr($level, 0, 16)),
                'message' => pSQL($message, true),
                'context' => $context === [] ? null : pSQL((string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), true),
                'date_add' => date('Y-m-d H:i:s'),
            ], true);
        } catch (\Throwable $e) {
            error_log(sprintf('[bxmp][%s][%s] %s (log failure: %s)', $channel, $level, $message, $e->getMessage()));
        }
    }

    /** Latest log entries (admin / API diagnostics). */
    public static function latest(int $limit = 100, ?string $channel = null): array
    {
        return \Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'bxmp_log`'
            . ($channel !== null ? ' WHERE channel = \'' . pSQL($channel) . '\'' : '')
            . ' ORDER BY id_log DESC LIMIT ' . max(1, $limit)) ?: [];
    }
}
