<?php

namespace App\Jobs;

use App\Models\Upload;
use App\Models\VideoList;
use FFMpeg\Coordinate\Dimension;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\Format\Video\X264;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class ConvertVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private X264 $format;
    private int $start;
    private int $end;
    private int $height;
    private int $width;
    private string $guid;
    private string $fileLocation;

    /**
     * @param array $convertData
     */
    public function __construct(array $convertData)
    {
        $this->fileLocation = $convertData['fileLocation'];
        $this->format = $convertData['format'];
        $this->start = $convertData['start'];
        $this->end = $convertData['end'];
        $this->guid = $convertData['guid'];
        $this->height = $convertData['height'];
        $this->width = $convertData['width'];
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $video = FFMpeg::open($this->fileLocation);
        $video->filters()->resize(new Dimension($this->width, $this->height));
        $video->filters()->clip(TimeCode::fromSeconds($this->start), TimeCode::fromSeconds($this->end));
        $type = VideoList::whereGuid($this->guid)->first()->value('type');
        $this->format->on('progress', function ($percentage, $remaining, $rate) use ($type) {
            switch ($type) {
                case 'Upload':
                    Upload::whereGuid($this->guid)->update(['convert_progress' => $percentage, 'convert_remaining' => $remaining, 'convert_rate' => $rate]);
                    break;
            }
        });
        $video->export()->inFormat($this->format)->save(storage_path('converted/'.$type.'/'.$this->guid.'.mp4'));
    }
}
