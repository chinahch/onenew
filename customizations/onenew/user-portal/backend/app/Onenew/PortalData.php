<?php
namespace App\Onenew;

/** Pure presentation mappings. XB owns all balances, traffic and subscription state. */
final class PortalData
{
    public static function iso($value): ?string
    {
        if ($value === null || $value === '') return null;
        if ($value instanceof \DateTimeInterface) return $value->format(DATE_ATOM);
        return is_numeric($value) ? gmdate('Y-m-d\TH:i:s\Z', (int)$value) : (string)$value;
    }

    public static function period(string $period): array
    {
        $period = \App\Services\PlanService::getPeriodKey($period);
        $periods = \App\Models\Plan::getAvailablePeriods();
        return ['period' => $period, 'period_label' => $periods[$period]['name'] ?? $period,
            'duration_days' => $periods[$period]['days'] ?? null];
    }

    public static function plan($plan, ?string $period = null): ?array
    {
        if (!$plan) return null;
        $periods = [];
        foreach (($plan->prices ?? []) as $key => $price) {
            if ($price !== null && isset(\App\Models\Plan::getAvailablePeriods()[$key]) && $key !== 'onetime' && $key !== 'reset_traffic') {
                $periods[] = self::period($key) + ['price_cents' => (int)round((float)$price * 100)];
            }
        }
        $selected = null;
        foreach ($periods as $option) if ($option['period'] === $period) $selected = $option;
        $selected = $selected ?? ($periods[0] ?? []);
        return ['id' => $plan->id, 'name' => $plan->name, 'quota_bytes' => (int)round($plan->transfer_enable * 1073741824),
            'renew' => (bool)$plan->renew, 'periods' => $periods] + $selected;
    }

    public static function order($order): array
    {
        return ['id' => $order->trade_no, 'trade_no' => $order->trade_no, 'plan_name' => $order->plan?->name ?? '已删除套餐',
            'quota_bytes' => $order->plan ? (int)round($order->plan->transfer_enable * 1073741824) : null,
            'price_cents' => (int)$order->total_amount, 'balance_cents' => (int)$order->balance_amount,
            'discount_cents' => (int)$order->discount_amount, 'surplus_cents' => (int)$order->surplus_amount,
            'handling_cents' => (int)$order->handling_amount,
            'status' => [0=>'pending',1=>'processing',2=>'cancelled',3=>'approved',4=>'discounted'][(int)$order->status] ?? 'unknown',
            'created_at' => self::iso($order->created_at), 'paid_at' => self::iso($order->paid_at)] + self::period($order->period);
    }
}
