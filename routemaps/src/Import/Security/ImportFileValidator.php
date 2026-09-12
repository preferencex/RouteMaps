<?php

declare(strict_types=1);

namespace RouteMaps\Core\Import\Security;

use finfo;
use InvalidArgumentException;
use RouteMaps\Core\Import\ImportFile;
use RuntimeException;

final class ImportFileValidator {
    public const DEFAULT_MAX_BYTES = 10 * 1024 * 1024;
    public const DEFAULT_MAX_EXPANDED_BYTES = 50 * 1024 * 1024;
    public const DEFAULT_MAX_EXPANSION_RATIO = 50.0;

    /** @var array<string,list<string>> */
    private const MIME_BY_EXTENSION = [
        'kml' => [
            'application/vnd.google-earth.kml+xml',
            'application/xml',
            'text/xml',
            'text/plain',
            'application/octet-stream',
        ],
        'kmz' => [
            'application/vnd.google-earth.kmz',
            'application/zip',
            'application/x-zip-compressed',
            'application/octet-stream',
        ],
        'geojson' => [
            'application/geo+json',
            'application/json',
            'text/plain',
            'application/octet-stream',
        ],
    ];

    public function __construct(
        private ?int $maxBytes = null,
        private ?int $maxExpandedBytes = null,
        private ?float $maxExpansionRatio = null
    ) {
    }

    public function validate(ImportFile $file): void {
        $extension = $file->extension();
        if (!isset(self::MIME_BY_EXTENSION[$extension])) {
            throw new InvalidArgumentException('import_extension_unsupported');
        }

        $maxBytes = $this->intLimit(
            $this->maxBytes,
            self::DEFAULT_MAX_BYTES,
            'routemaps_import_max_bytes'
        );
        if ($file->size() > $maxBytes) {
            throw new InvalidArgumentException('import_file_too_large');
        }

        $allowedMimes = self::MIME_BY_EXTENSION[$extension];
        $clientMime = $this->normalizeMime($file->clientMimeType());
        if (null !== $clientMime && !in_array($clientMime, $allowedMimes, true)) {
            throw new InvalidArgumentException('import_mime_mismatch');
        }

        $detectedMime = $this->detectedMime($file);
        if (!in_array($detectedMime, $allowedMimes, true)) {
            throw new InvalidArgumentException('import_mime_mismatch');
        }

        if ('kml' === $extension) {
            $this->validateKml($file);
        } elseif ('kmz' === $extension) {
            $this->validateKmz($file);
        }
    }

    private function validateKml(ImportFile $file): void {
        $contents = $file->contents();
        if (1 === preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $contents)) {
            throw new InvalidArgumentException('import_kml_external_entity');
        }
    }

    private function validateKmz(ImportFile $file): void {
        $bytes = $file->contents();
        $eocdOffset = strrpos($bytes, "PK\x05\x06");
        if (false === $eocdOffset || strlen($bytes) < $eocdOffset + 22) {
            throw new InvalidArgumentException('import_kmz_invalid_archive');
        }

        $eocd = unpack(
            'vdisk/vcentral_disk/ventries_disk/ventries/Vcentral_size/Vcentral_offset/vcomment_length',
            substr($bytes, $eocdOffset + 4, 18)
        );
        if (!is_array($eocd)
            || 0 !== (int) $eocd['disk']
            || 0 !== (int) $eocd['central_disk']
            || (int) $eocd['entries'] !== (int) $eocd['entries_disk']
        ) {
            throw new InvalidArgumentException('import_kmz_invalid_archive');
        }

        $entries = (int) $eocd['entries'];
        $offset = (int) $eocd['central_offset'];
        $centralSize = (int) $eocd['central_size'];
        if ($offset < 0 || $centralSize < 0 || $offset + $centralSize > strlen($bytes)) {
            throw new InvalidArgumentException('import_kmz_invalid_archive');
        }

        $totalCompressed = 0;
        $totalUncompressed = 0;
        $hasKml = false;

        for ($index = 0; $index < $entries; ++$index) {
            if (substr($bytes, $offset, 4) !== "PK\x01\x02" || strlen($bytes) < $offset + 46) {
                throw new InvalidArgumentException('import_kmz_invalid_archive');
            }

            $header = unpack(
                'vversion_made/vversion_needed/vflags/vcompression/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vname_len/vextra_len/vcomment_len/vdisk_start/vinternal/Vexternal/Vlocal_offset',
                substr($bytes, $offset + 4, 42)
            );
            if (!is_array($header)) {
                throw new InvalidArgumentException('import_kmz_invalid_archive');
            }

            $nameLength = (int) $header['name_len'];
            $extraLength = (int) $header['extra_len'];
            $commentLength = (int) $header['comment_len'];
            $recordLength = 46 + $nameLength + $extraLength + $commentLength;
            if ($nameLength <= 0 || strlen($bytes) < $offset + $recordLength) {
                throw new InvalidArgumentException('import_kmz_invalid_archive');
            }

            $name = substr($bytes, $offset + 46, $nameLength);
            $normalizedName = str_replace('\\', '/', $name);
            if ($this->isUnsafeArchivePath($normalizedName)) {
                throw new InvalidArgumentException('import_kmz_path_traversal');
            }

            $flags = (int) $header['flags'];
            if (0 !== ($flags & 0x0001) || 0 !== ($flags & 0x0040)) {
                throw new InvalidArgumentException('import_kmz_encrypted_entry');
            }

            $compressed = (int) $header['compressed'];
            $uncompressed = (int) $header['uncompressed'];
            if (0xFFFFFFFF === $compressed || 0xFFFFFFFF === $uncompressed) {
                throw new InvalidArgumentException('import_kmz_invalid_archive');
            }
            if ($compressed < 0 || $uncompressed < 0) {
                throw new InvalidArgumentException('import_kmz_invalid_archive');
            }

            if (!str_ends_with($normalizedName, '/')) {
                $totalCompressed += $compressed;
                $totalUncompressed += $uncompressed;
                if (str_ends_with(strtolower($normalizedName), '.kml')) {
                    $hasKml = true;
                }

                if ($uncompressed > 0 && 0 === $compressed) {
                    throw new InvalidArgumentException('import_kmz_expansion_limit');
                }
                if ($compressed > 0 && ($uncompressed / $compressed) > $this->floatLimit(
                    $this->maxExpansionRatio,
                    self::DEFAULT_MAX_EXPANSION_RATIO,
                    'routemaps_import_max_expansion_ratio'
                )) {
                    throw new InvalidArgumentException('import_kmz_expansion_limit');
                }
            }

            $offset += $recordLength;
        }

        if (!$hasKml) {
            throw new InvalidArgumentException('import_kmz_kml_missing');
        }

        if ($totalUncompressed > $this->intLimit(
            $this->maxExpandedBytes,
            self::DEFAULT_MAX_EXPANDED_BYTES,
            'routemaps_import_max_expanded_bytes'
        )) {
            throw new InvalidArgumentException('import_kmz_expanded_size_limit');
        }

        if ($totalCompressed > 0 && ($totalUncompressed / $totalCompressed) > $this->floatLimit(
            $this->maxExpansionRatio,
            self::DEFAULT_MAX_EXPANSION_RATIO,
            'routemaps_import_max_expansion_ratio'
        )) {
            throw new InvalidArgumentException('import_kmz_expansion_limit');
        }
    }

    private function isUnsafeArchivePath(string $path): bool {
        if ('' === $path || str_contains($path, "\0")) {
            return true;
        }
        if ('/' === $path[0] || 1 === preg_match('/^[A-Za-z]:\//', $path)) {
            return true;
        }
        foreach (explode('/', $path) as $segment) {
            if ('..' === $segment) {
                return true;
            }
        }
        return false;
    }

    private function detectedMime(ImportFile $file): string {
        if (!class_exists(finfo::class)) {
            return 'application/octet-stream';
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file->path());
        return is_string($mime) && '' !== $mime ? strtolower($mime) : 'application/octet-stream';
    }

    private function normalizeMime(?string $mime): ?string {
        if (null === $mime || '' === trim($mime)) {
            return null;
        }
        return strtolower(trim(explode(';', $mime, 2)[0]));
    }

    private function intLimit(?int $configured, int $default, string $filter): int {
        $value = $configured ?? $default;
        if (null === $configured && function_exists('apply_filters')) {
            $filtered = apply_filters($filter, $default);
            if (is_numeric($filtered)) {
                $value = (int) $filtered;
            }
        }
        if ($value <= 0) {
            throw new RuntimeException('import_limit_invalid');
        }
        return $value;
    }

    private function floatLimit(?float $configured, float $default, string $filter): float {
        $value = $configured ?? $default;
        if (null === $configured && function_exists('apply_filters')) {
            $filtered = apply_filters($filter, $default);
            if (is_numeric($filtered)) {
                $value = (float) $filtered;
            }
        }
        if ($value <= 0) {
            throw new RuntimeException('import_limit_invalid');
        }
        return $value;
    }
}
