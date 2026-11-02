<?php

declare(strict_types=1);

namespace BrainX\Csv;

/** Raised by ImportService when an import cannot be enqueued (bad vendor, type, file, header, path...). */
final class ImportException extends \InvalidArgumentException
{
}
