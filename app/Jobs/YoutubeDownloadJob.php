<?php

namespace App\Jobs;

use App\Models\Conversion;
use App\Models\Youtube;
use App\Utilities\Converter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Storage;
use YoutubeDl\Options;
use YoutubeDownload;
use Exception;

class YoutubeDownloadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int */
    public int $tries = 1;

    /** @var int */
    public int $timeout = 3600;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(private Youtube $youtube, private Conversion $conversion) {}

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $collection = YoutubeDownload::onProgress(static function (?string $progressTarget, string $percentage, string $size, string $speed, string $eta, ?string $totalTime) {
            $this->youtube->update(
                [
                    'progress' => $percentage,
                    'rate' => $speed,
                    'eta' => $eta
                ]
            );
        })
            ->download(
                Options::create()
                    ->continue(true)
                    ->restrictFileNames(true)
                    ->format('best')
                    ->downloadPath(Storage::disk($this->conversion->source_disk)->path('/'))
                    ->url($this->youtube->url)
                    ->noPlaylist()
                    ->maxDownloads(1)
                    ->subLang([$this->youtube->subtitle])
                    ->writeSub(true)
                    ->embedSubs(true)
            );

        foreach ($collection->getVideos() as $video) {
            Storage::disk($this->conversion->source_disk)->move($video->getFile()->getPathname(), $this->conversion->guid);
        }

        try {
            $converter = new Converter(Storage::disk($this->conversion->source_disk)->path($this->conversion->filename), $this->conversion);
        } catch (Exception $exception) {
            $this->conversion->failed = true;
            $this->conversion->probe_error = $exception->getMessage();
            $this->conversion->save();
            return;
        }
        dispatch((new ConvertVideoJob($converter->getFFMpegConfig()))->onQueue('convert'));
    }
}
