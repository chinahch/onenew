<?php
namespace App\Onenew\AiOperations;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

final class OperatorJob implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable;
    public int $tries=1;
    public int $timeout=240;
    public bool $failOnTimeout=true;
    public function __construct(public string $id){$this->onConnection('onenew_ai_operator');$this->onQueue('onenew_ai_operator');}
    public function handle(OperatorTasks $tasks):void {$tasks->run($this->id);}
    public function failed(?\Throwable $exception):void {app(OperatorTasks::class)->fail($this->id,$exception,'queue_failed');}
}
