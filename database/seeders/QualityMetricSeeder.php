<?php

namespace Database\Seeders;

use App\Data\StatusFlags;
use App\Enums\Metric;
use App\Enums\Status;
use App\Models\QualityMetric;
use Illuminate\Database\Seeder;

/**
 * Seeds the metric nomenclature from the Metric enum, so every case has a row with the same slug.
 * Safe to re-run: the status of an existing metric is left untouched.
 */
class QualityMetricSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Metric::cases() as $metric) {
            $row = QualityMetric::firstOrNew(['slug' => $metric->value]);
            $row->name = $metric->label();

            if (! $row->exists) {
                $row->status = StatusFlags::of(Status::Active);
            }

            $row->save();
        }
    }
}
