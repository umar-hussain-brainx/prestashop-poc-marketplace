<?php

declare(strict_types=1);

namespace BrainX\Csv\Parser;

/** File-level CSV problem (unreadable file, encoding, missing columns...): the whole job fails. */
final class CsvException extends \RuntimeException
{
}
