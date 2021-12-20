<?php
declare(strict_types=1);

namespace Elephox\Stream;

use Elephox\Files\Contract\File as FileContract;
use Elephox\Files\File;
use Elephox\Stream\Contract\Stream;
use InvalidArgumentException;
use JetBrains\PhpStorm\Pure;
use RuntimeException;

class ResourceStream implements Stream
{
	public static function fromFile(string|FileContract $file, bool $readable = true, bool $writeable = false, bool $create = false, bool $append = false, bool $truncate = false): self
	{
		if (is_string($file)) {
			$file = new File($file);
		}

		if ($readable && !$file->isReadable()) {
			throw new UnreadableFileException($file->getPath());
		}

		if (($writeable || $append) && !$file->isWritable()) {
			throw new UnwritableFileException($file->getPath());
		}

		if ($create && $file->getParent()->isReadonly()) {
			throw new ReadonlyParentException($file->getPath());
		}

		$flags = match (true) {
			 $readable &&  $writeable &&  $create &&  $append && !$truncate => 'ab+',
			!$readable &&  $writeable &&  $create &&  $append && !$truncate => 'ab',
			 $readable &&  $writeable &&  $create && !$append &&  $truncate => 'wb+',
			!$readable &&  $writeable &&  $create && !$append &&  $truncate => 'wb',
			 $readable &&  $writeable &&  $create && !$append && !$truncate => 'cb+',
			!$readable &&  $writeable &&  $create && !$append && !$truncate => 'cb',
			 $readable &&  $writeable && !$create && !$append && !$truncate => 'rb+',
			 $readable && !$writeable && !$create && !$append && !$truncate => 'rb',
			default => throw new InvalidArgumentException('Invalid combination of flags: readable=' . ($readable ?: '0') . ', writeable=' . ($writeable ?: '0') . ', create=' . ($create ?: '0') . ', append=' . ($append ?: '0') . ', truncate=' . ($truncate ?: '0')),
		};

		return new ResourceStream(fopen($file->getPath(), $flags), $readable, $writeable, $readable);
	}

	/**
	 * @param closed-resource|resource|null $resource
	 * @param bool $readable
	 * @param bool $writable
	 * @param bool $seekable
	 * @param null|positive-int|0 $size
	 */
	public function __construct(
		protected $resource,
		protected bool $readable = true,
		protected bool $writable = true,
		protected bool $seekable = true,
		protected ?int $size = null
	)
	{
		if (!is_resource($this->resource)) {
			throw new InvalidArgumentException('ResourceStream expects a resource');
		}
	}

	public function __toString(): string
	{
		if (!is_resource($this->resource)) {
			return "";
		}

		if ($this->isSeekable()) {
			$this->rewind();
		}

		return $this->getContents();
	}

	public function detach()
	{
		if (!is_resource($this->resource)) {
			return null;
		}

		$resource = $this->resource;

		unset($this->resource);

		return $resource;
	}

	public function close(): void
	{
		if (!is_resource($this->resource)) {
			return;
		}

		fclose($this->resource);

		$this->detach();
	}

	public function getSize(): ?int
	{
		if ($this->size !== null) {
			return $this->size;
		}

		if (!is_resource($this->resource)) {
			throw new RuntimeException('Resource is not available');
		}

		$stats = fstat($this->resource);
		if (is_array($stats) && isset($stats['size'])) {
			/** @var positive-int|0 */
			$this->size = $stats['size'];
		}

		return $this->size;
	}

	public function tell(): int
	{
		if (!is_resource($this->resource)) {
			throw new RuntimeException('Resource is not available');
		}

		/** @var positive-int|false|0 $position */
		$position = ftell($this->resource);

		if ($position === false) {
			throw new RuntimeException('Unable to determine the current position');
		}

		return $position;
	}

	public function eof(): bool
	{
		if (!is_resource($this->resource)) {
			throw new RuntimeException('Resource is not available');
		}

		return feof($this->resource);
	}

	#[Pure] public function isSeekable(): bool
	{
		return $this->seekable;
	}

	public function seek($offset, $whence = SEEK_SET): void
	{
		if (!is_resource($this->resource)) {
			throw new RuntimeException('Resource is not available');
		}

		if (!$this->seekable) {
			throw new RuntimeException('Resource is not seekable');
		}

		if (fseek($this->resource, $offset, $whence) === -1) {
			throw new RuntimeException('Unable to seek to resource position ' . $offset . ' with whence ' . $whence);
		}
	}

	public function rewind(): void
	{
		$this->seek(0);
	}

	#[Pure] public function isWritable(): bool
	{
		return $this->writable;
	}

	public function write(string $string): int
	{
		if (!is_resource($this->resource)) {
			throw new RuntimeException('Resource is not available');
		}

		if (!$this->writable) {
			throw new RuntimeException('Cannot write to a non-writable resource');
		}

		$this->size = null;

		/** @var false|positive-int|0 $written */
		$written = fwrite($this->resource, $string);
		if ($written === false) {
			throw new RuntimeException('Unable to write to resource');
		}

		return $written;
	}

	#[Pure] public function isReadable(): bool
	{
		return $this->readable;
	}

	public function read(int $length): string
	{
		if ($length < 0) {
			throw new InvalidArgumentException('Length parameter cannot be negative');
		}

		if (!is_resource($this->resource)) {
			throw new RuntimeException('Resource is not available');
		}

		if (!$this->readable) {
			throw new RuntimeException('Cannot read from non-readable stream');
		}

		if (0 === $length) {
			return '';
		}

		$buffer = fread($this->resource, $length);
		if ($buffer === false) {
			throw new RuntimeException('Unable to read from stream');
		}

		return $buffer;
	}

	public function getContents(): string
	{
		if (!is_resource($this->resource)) {
			throw new RuntimeException('Resource is not available');
		}

		$contents = stream_get_contents($this->resource);
		if ($contents === false) {
			throw new RuntimeException('Unable to read resource contents');
		}

		return $contents;
	}

	public function getMetadata(?string $key = null): mixed
	{
		if (!is_resource($this->resource)) {
			return $key ? null : [];
		}

		$meta = stream_get_meta_data($this->resource);

		if ($key === null) {
			return $meta;
		}

		return $meta[$key] ?? null;
	}
}
