<?php
namespace App\Onenew\AiOperations;
final class PruneOperatorTasks extends \Illuminate\Console\Command
{
    protected $signature='onenew:prune-ai-tasks';
    protected $description='Remove expired encrypted AI tasks and fail abandoned operator runs';
    public function handle(OperatorTasks $tasks):int {$tasks->prune();return 0;}
}
