<?php
namespace App\Onenew\AiOperations;
final class Knowledge {
 public static function entries(string $scope,string $question=''):array {
  $rows=json_decode(file_get_contents(__DIR__.'/knowledge.json'),true,32,JSON_THROW_ON_ERROR);
  return $rows; // Fixed small versioned library: a node-page question may still concern billing or backups.
 }
}
