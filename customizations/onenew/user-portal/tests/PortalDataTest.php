<?php
namespace Tests\Unit;

use App\Models\Order;
use App\Models\Plan;
use App\Onenew\PortalData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PortalDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_gb_and_currency_are_converted_once_and_all_renewal_periods_survive(): void
    {
        $plan=new Plan(['name'=>'QA mapping plan','transfer_enable'=>100,'renew'=>true,'prices'=>['monthly'=>19.99,'quarterly'=>55,'yearly'=>180,'onetime'=>300,'reset_traffic'=>5]]);
        $plan->id=7;
        $data=PortalData::plan($plan,'quarterly');
        self::assertSame(107374182400,$data['quota_bytes']);
        self::assertSame(['monthly','quarterly','yearly'],array_column($data['periods'],'period'));
        self::assertSame(1999,$data['periods'][0]['price_cents']);
        self::assertSame(5500,$data['price_cents']);
        self::assertSame('季付',$data['period_label']);
    }

    public function test_native_order_cents_statuses_and_absent_plan_are_not_invented(): void
    {
        foreach([0=>'pending',1=>'processing',2=>'cancelled',3=>'approved',4=>'discounted'] as $status=>$expected){
            $order=new Order(['trade_no'=>'qa-mapping-only','period'=>'month_price','total_amount'=>1250,'balance_amount'=>750,'status'=>$status,'created_at'=>1700000000]);
            $order->setRelation('plan',null);
            $data=PortalData::order($order);
            self::assertSame(1250,$data['price_cents']);
            self::assertSame(750,$data['balance_cents']);
            self::assertSame($expected,$data['status']);
            self::assertNull($data['quota_bytes']);
            self::assertSame('monthly',$data['period']);
            self::assertSame('2023-11-14T22:13:20Z',$data['created_at']);
        }
    }

    public function test_seconds_iso_and_null_are_preserved(): void
    {
        self::assertNull(PortalData::iso(null));
        self::assertSame('2023-11-14T22:13:20Z',PortalData::iso(1700000000));
        self::assertSame('2026-12-01T00:00:00+08:00',PortalData::iso('2026-12-01T00:00:00+08:00'));
    }
}
