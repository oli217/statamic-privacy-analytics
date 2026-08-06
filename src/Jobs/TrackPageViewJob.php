<?php

namespace Oliweb\StatamicAnalytics\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Oliweb\StatamicAnalytics\Services\PageViewRecorder;

class TrackPageViewJob implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 30, 60];

    public function __construct(protected array $data)
    {
    }

    public function handle(PageViewRecorder $recorder): void
    {
        $recorder->record($this->data);
    }
}
