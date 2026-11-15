<?php
namespace App\Onenew\LandingLibrary;
use Illuminate\Support\Facades\DB;
/** Native save is the transaction boundary, including native after-commit observers. */
trait HasLandingBindings
{
    public function save(array $options=[])
    {
        return DB::transaction(function()use($options){
            // Serializes reference discovery against assignments/deletions, including null -> binding.
            if ($this->onenew_landing_bindings!==null || $this->getRawOriginal('onenew_landing_bindings')!==null) Library::lock();
            return parent::save($options);
        });
    }
    public static function bootHasLandingBindings(): void
    {
        static::saving(function($node){
            if($node->onenew_landing_bindings===null)return;
            if($node->isDirty(['onenew_landing_bindings','machine_id','parent_id','type','protocol_settings','custom_outbounds','custom_routes'])){
                $compiled=Library::compile($node);$node->custom_outbounds=$compiled['custom_outbounds'];$node->custom_routes=$compiled['custom_routes'];
            }
        });
    }
}

