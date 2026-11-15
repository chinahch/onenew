<?php
namespace Tests\Feature\Onenew;
use App\Models\Server;
use App\Models\User;
use App\Onenew\LandingLibrary\Library;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
class LandingLibraryTest extends TestCase
{
    use RefreshDatabase;
    private const URL='/api/onenew/admin/landings';
    private function admin(bool $admin=true): void {
        Sanctum::actingAs(User::create(['email'=>uniqid().'@example.test','password'=>'unused','uuid'=>(string)\Illuminate\Support\Str::uuid(),'token'=>bin2hex(random_bytes(16)),'is_admin'=>$admin,'transfer_enable'=>1000,'u'=>0,'d'=>0,'banned'=>0]));
    }
    private function draft(string $host='exit.example'): array {
        return ['name'=>'Exit','link'=>'socks://'.base64_encode('fixture:secret').'@'.$host.':1080','enabled'=>true,'sort'=>0,'variants'=>[
            'singbox'=>['tag'=>'draft','protocol'=>'socks','settings'=>['server'=>$host,'server_port'=>1080,'username'=>'fixture','password'=>'secret']],
            'xray'=>['tag'=>'draft','protocol'=>'socks','settings'=>['servers'=>[['address'=>$host,'port'=>1080,'users'=>[['user'=>'fixture','pass'=>'secret']]]]]]]];
    }
    private function node(): Server {
        $node=Server::create(['name'=>'Ingress','type'=>'vmess','host'=>'ingress.example','port'=>443,'server_port'=>443,'rate'=>1,'group_ids'=>[],'enabled'=>true]);
        ServerService::updateMetrics($node,['kernel_type'=>'singbox','onenew_runtime'=>true]);return $node;
    }
    public function test_admin_only_encrypted_crud_and_optimistic_versions(): void {
        $this->getJson(self::URL)->assertForbidden();$this->admin(false);$this->postJson(self::URL,$this->draft())->assertForbidden();$this->admin();
        $id=$this->postJson(self::URL,$this->draft())->assertOk()->json('id');
        $this->assertStringNotContainsString('secret',DB::table('onenew_landings')->find($id)->link_ciphertext);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.0.host','exit.example')->assertJsonMissingPath('data.0.link')->assertJsonMissingPath('data.0.variants');
        $this->getJson(self::URL.'/'.$id)->assertOk()->assertJsonPath('variants.singbox.settings.password','secret');
        $this->postJson(self::URL.'/'.$id,$this->draft()+['version'=>1])->assertOk()->assertJsonPath('version',2);
        $this->postJson(self::URL.'/'.$id,$this->draft()+['version'=>1])->assertConflict();
        $this->postJson(self::URL.'/'.$id.'/delete',['version'=>1])->assertConflict();
        $this->postJson(self::URL.'/'.$id.'/delete',['version'=>2])->assertOk();$this->assertDatabaseCount('onenew_landings',0);
    }
    public function test_binding_order_reference_protection_and_successful_regeneration(): void {
        $this->admin();$id=$this->postJson(self::URL,$this->draft())->json('id');$other=$this->postJson(self::URL,$this->draft('other.example'))->json('id');$node=$this->node();
        $node->onenew_landing_bindings=[['landing_id'=>$other,'global'=>false,'domains'=>['video.example']],['landing_id'=>$id,'global'=>true,'domains'=>[]]];$node->save();
        $this->assertSame('landing_'.$other,$node->custom_routes[0]['outbound']);$this->assertSame('landing_'.$id,$node->custom_routes[1]['outbound']);
        $this->getJson(self::URL)->assertJsonPath('data.0.reference_count',1);
        $this->postJson(self::URL.'/'.$id.'/delete',['version'=>1])->assertConflict();
        $disabled=$this->draft();$disabled['enabled']=false;$this->postJson(self::URL.'/'.$id,$disabled+['version'=>1])->assertConflict();
        $this->postJson(self::URL.'/'.$id,$this->draft('updated.example')+['version'=>1])->assertOk();
        $this->assertSame('updated.example',$node->fresh()->custom_outbounds[1]['settings']['server']);
        $node->onenew_landing_bindings=[];$node->save();$this->assertSame([],$node->fresh()->custom_outbounds);$this->assertSame([],$node->fresh()->custom_routes);
    }
    public function test_failed_recompile_rolls_back_every_node_and_library(): void {
        $this->admin();$id=$this->postJson(self::URL,$this->draft())->json('id');$a=$this->node();$b=$this->node();
        foreach([$a,$b] as $node){$node->onenew_landing_bindings=[['landing_id'=>$id,'global'=>true,'domains'=>[]]];$node->save();}
        ServerService::updateMetrics($b,['kernel_type'=>'xray','onenew_runtime'=>false]);
        $draft=$this->draft('changed.example');unset($draft['variants']['xray']);
        $this->postJson(self::URL.'/'.$id,$draft+['version'=>1])->assertUnprocessable();
        $this->assertSame('exit.example',$a->fresh()->custom_outbounds[0]['settings']['server']);
        $this->getJson(self::URL.'/'.$id)->assertJsonPath('version',1)->assertJsonPath('variants.singbox.settings.server','exit.example');
    }
    public function test_validation_rejects_endpoint_mismatch_empty_variants_duplicate_domains_and_stale_runtime(): void {
        $this->admin();$bad=$this->draft();$bad['variants']=[];$this->postJson(self::URL,$bad)->assertUnprocessable();
        $bad=$this->draft();$bad['variants']['singbox']['settings']['server']='different.example';$this->postJson(self::URL,$bad)->assertUnprocessable();
        $id=$this->postJson(self::URL,$this->draft())->json('id');$node=$this->node();
        foreach([
            [['landing_id'=>$id,'global'=>true,'domains'=>['duplicate.example','duplicate.example']]],
            [['landing_id'=>$id,'global'=>true,'domains'=>[]],['landing_id'=>$id,'global'=>false,'domains'=>['ok.example']]],
            [['landing_id'=>$id,'global'=>false,'domains'=>['https://invalid.example']]],
        ] as $bindings){try{$node->onenew_landing_bindings=$bindings;$node->save();$this->fail('Invalid binding accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(422,$e->getStatusCode());}}
        $this->travel(601)->seconds();try{$node->onenew_landing_bindings=[['landing_id'=>$id,'global'=>true,'domains'=>[]]];$node->save();$this->fail('Stale runtime accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(422,$e->getStatusCode());}
    }
    public function test_null_bindings_preserve_native_json_on_unrelated_save(): void {
        $node=$this->node();$out=[['tag'=>'legacy']];$node->custom_outbounds=$out;$node->custom_routes=[['outbound'=>'legacy']];$node->save();$node->name='Renamed';$node->save();$this->assertSame($out,$node->fresh()->custom_outbounds);
    }
    public function test_real_copy_action_drops_landing_and_route_state_without_touching_original(): void {
        $this->admin();$id=$this->postJson(self::URL,$this->draft())->assertOk()->json('id');$source=$this->node();
        $source->onenew_landing_bindings=[['landing_id'=>$id,'global'=>true,'domains'=>[]]];$source->save();
        $before=$source->fresh()->custom_outbounds;
        app(\App\Http\Controllers\V2\Admin\Server\ManageController::class)->copy(new \Illuminate\Http\Request(['id'=>$source->id]));
        $copy=Server::where('id','!=',$source->id)->firstOrFail();
        $this->assertSame([],$copy->onenew_landing_bindings);$this->assertSame([],$copy->custom_outbounds);$this->assertSame([],$copy->custom_routes);$this->assertSame([],$copy->route_ids);
        $this->assertFalse((bool)$copy->enabled);$this->assertNotSame($source->onenew_identity,$copy->onenew_identity);
        $this->assertSame($before,$source->fresh()->custom_outbounds);$this->assertSame(1,Library::references($id)->count());
    }
    public function test_library_bindings_refuse_ambiguous_rule_order_and_cross_landing_suffix_overlap(): void {
        foreach([
            [['landing_id'=>1,'global'=>true,'domains'=>[]],['landing_id'=>2,'global'=>false,'domains'=>['example.com']]],
            [['landing_id'=>1,'global'=>false,'domains'=>['example.com']],['landing_id'=>2,'global'=>false,'domains'=>['sub.example.com']]],
        ] as $bindings){
            try{\App\Onenew\LandingLibrary\Compiler::bindings($bindings);$this->fail('ambiguous bindings accepted');}
            catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(422,$e->getStatusCode());}
        }
        $value=[['landing_id'=>1,'global'=>false,'domains'=>['example.com','sub.example.com']]];
        $this->assertSame($value,\App\Onenew\LandingLibrary\Compiler::bindings($value));
    }

    public function test_link_remark_names_are_derived_and_explicit_names_stay_unchanged(): void {
        $this->admin();$draft=$this->draft();$draft['name']=null;$draft['link'].='#'.rawurlencode('马来家宽');
        $id=$this->postJson(self::URL,$draft)->assertOk()->json('id');
        $this->getJson(self::URL.'/'.$id)->assertJsonPath('name','马来家宽');
        $draft['name']='自定义名称';$id2=$this->postJson(self::URL,$draft)->assertOk()->json('id');
        $this->getJson(self::URL.'/'.$id2)->assertJsonPath('name','自定义名称');
        $draft['name']='落地 exit.example';$old=$this->postJson(self::URL,$draft)->assertOk()->json('id');
        $migration=require '/opt/onenew/landing-library/database/migrations/2026_11_15_000002_recover_landing_link_names.php';$migration->up();
        $this->getJson(self::URL.'/'.$old)->assertJsonPath('name','马来家宽')->assertJsonPath('version',2);
        $this->getJson(self::URL.'/'.$id2)->assertJsonPath('name','自定义名称');
    }

}
