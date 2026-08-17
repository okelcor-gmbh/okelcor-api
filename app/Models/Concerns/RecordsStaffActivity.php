<?php

namespace App\Models\Concerns;

use App\Services\StaffActivityRecorder;
use Illuminate\Support\Facades\Log;

/**
 * Wires a model into the contribution ledger.
 *
 * Eloquent calls `bootRecordsStaffActivity()` for every model using this trait,
 * so a model joins the ledger by adding the trait and one method — there is no
 * registration list to keep in step, and no call site that can forget.
 *
 * The try/catch lives here rather than in each model because it is the rule
 * that matters most and the one easiest to omit by accident: an order that
 * confirmed correctly must never fail because a reporting row could not be
 * written. Six copies of that guard is six chances to write it wrong once.
 */
trait RecordsStaffActivity
{
    protected static function bootRecordsStaffActivity(): void
    {
        static::saved(function ($model) {
            try {
                $model->recordStaffActivity(app(StaffActivityRecorder::class));
            } catch (\Throwable $e) {
                Log::warning('Staff activity hook failed', [
                    'model' => $model::class,
                    'id'    => $model->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Hand this model to the recorder. Returning without calling it is a valid
     * outcome — plenty of saves are not somebody's work.
     */
    abstract public function recordStaffActivity(StaffActivityRecorder $recorder): void;
}
