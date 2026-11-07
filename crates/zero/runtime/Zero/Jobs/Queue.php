<?php

declare(strict_types=1);

namespace Zero\Jobs;

/** Delivery of a queued job row: WordPress hands it to Action Scheduler (Wp\Jobs\ActionSchedulerQueue). */
interface Queue
{
    /** Called inside the dispatch/runner transaction once `id` is pending in the jobs table. */
    public function schedule(string $id, string $runAtIso): void;
}
