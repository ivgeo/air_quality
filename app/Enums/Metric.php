<?php

namespace App\Enums;

/**
 * Air quality metrics known to the application.
 *
 * The values are the single source of truth for metric identifiers: they match
 * `quality_metric.slug` and the keys of the hourly `data` JSON column.
 */
enum Metric: string
{
    case Pm25 = 'pm25';
    case Pm10 = 'pm10';

    public function label(): string
    {
        return match ($this) {
            self::Pm25 => 'PM2.5',
            self::Pm10 => 'PM10',
        };
    }

    /**
     * The unit the metric's values are measured and stored in.
     */
    public function unit(): string
    {
        return match ($this) {
            self::Pm25, self::Pm10 => 'µg/m³',
        };
    }
}
