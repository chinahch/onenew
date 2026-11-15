<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Onenew\LandingLibrary\Library;
use App\Onenew\External\ShareLink;
return new class extends Migration {
 public function up(): void {
  DB::table(Library::TABLE)->orderBy('id')->chunkById(100,function($rows){foreach($rows as $row){
   try{$payload=Library::payload($row);$parsed=ShareLink::parse($payload['link']);$name=trim($parsed['name']);}
   catch(\Throwable){continue;}
   if($name!=='' && $row->name==='落地 '.$parsed['host'])DB::table(Library::TABLE)->where('id',$row->id)->where('version',$row->version)->update(['name'=>Library::name($payload['link']),'version'=>(int)$row->version+1,'updated_at'=>now()]);
  }});
 }
 public function down(): void {}
};
