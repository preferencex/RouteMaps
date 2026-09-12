<?php

declare(strict_types=1);

namespace RouteMaps\Core\Import;

use InvalidArgumentException;
use RuntimeException;

final class ImportFile {
    public function __construct(
        private string $path,
        private string $originalName,
        private ?string $clientMimeType = null
    ) {
        if ('' === trim($this->originalName) || str_contains($this->originalName, "\0")) {
            throw new InvalidArgumentException('import_file_name_invalid');
        }

        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new InvalidArgumentException('import_file_unreadable');
        }
    }

    public function path(): string {
        return $this->path;
    }

    public function originalName(): string {
        return $this->originalName;
    }

    public function clientMimeType(): ?string {
        return $this->clientMimeType;
    }

    public function extension(): string {
        return strtolower((string) pathinfo($this->originalName, PATHINFO_EXTENSION));
    }

    public function size(): int {
        $size = filesize($this->path);
        if (false === $size) {
            throw new RuntimeException('import_file_size_unavailable');
        }
        return $size;
    }

    public function contents(): string {
        $contents = file_get_contents($this->path);
        if (false === $contents) {
            throw new RuntimeException('import_file_read_failed');
        }
        return $contents;
    }
}
