<?php
declare(strict_types=1);

namespace Elephox\Stream;

use JetBrains\PhpStorm\Pure;
use RuntimeException;
use Throwable;

class UnreadableFileException extends RuntimeException
{
	#[Pure] public function __construct(string $path, int $code = 0, ?Throwable $previous = null)
	{
		parent::__construct("Unable to read file at $path", $code, $previous);
	}
}