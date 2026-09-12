<?php

declare(strict_types=1);

namespace RouteMaps\Core\Import\Geo;

use InvalidArgumentException;

final class CoordinateNormalizer {
    /** @param array<mixed> $coordinate @return array{0:float,1:float} */
    public function point(array $coordinate): array {
        if (count($coordinate) < 2 || !is_numeric($coordinate[0] ?? null) || !is_numeric($coordinate[1] ?? null)) {
            throw new InvalidArgumentException('import_coordinate_invalid');
        }

        $longitude = (float) $coordinate[0];
        $latitude = (float) $coordinate[1];
        if ($longitude < -180.0 || $longitude > 180.0 || $latitude < -90.0 || $latitude > 90.0) {
            throw new InvalidArgumentException('import_coordinate_out_of_bounds');
        }

        return [round($longitude, 7), round($latitude, 7)];
    }

    /** @param array<mixed> $coordinates @return list<array{0:float,1:float}> */
    public function line(array $coordinates): array {
        if (count($coordinates) < 2) {
            throw new InvalidArgumentException('import_line_requires_two_points');
        }

        $normalized = [];
        foreach ($coordinates as $coordinate) {
            if (!is_array($coordinate)) {
                throw new InvalidArgumentException('import_coordinate_invalid');
            }
            $normalized[] = $this->point($coordinate);
        }
        return $normalized;
    }

    /** @param array<mixed> $coordinates @return list<list<array{0:float,1:float}>> */
    public function multiLine(array $coordinates): array {
        if ([] === $coordinates) {
            throw new InvalidArgumentException('import_route_geometry_missing');
        }
        $normalized = [];
        foreach ($coordinates as $line) {
            if (!is_array($line)) {
                throw new InvalidArgumentException('import_coordinate_invalid');
            }
            $normalized[] = $this->line($line);
        }
        return $normalized;
    }

    /** @param array<mixed> $coordinates @return list<list<array{0:float,1:float}>> */
    public function polygon(array $coordinates): array {
        if ([] === $coordinates) {
            throw new InvalidArgumentException('import_polygon_invalid');
        }
        $rings = [];
        foreach ($coordinates as $ring) {
            if (!is_array($ring) || count($ring) < 4) {
                throw new InvalidArgumentException('import_polygon_invalid');
            }
            $rings[] = array_map(function (mixed $coordinate): array {
                if (!is_array($coordinate)) {
                    throw new InvalidArgumentException('import_coordinate_invalid');
                }
                return $this->point($coordinate);
            }, $ring);
        }
        return $rings;
    }
}
