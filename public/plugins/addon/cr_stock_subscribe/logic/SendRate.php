<?php
namespace addon\cr_stock_subscribe\logic;

final class SendRate
{
    // Runtime state is separate from editable settings and survives process
    // restarts, setting saves and native plugin upgrades. No schema migration.
    private const STATE_ID = 4;

    /** Called only inside Store::transaction, whose setting #1 lock serializes
     * reservations across cron, native queue workers and manual retries. */
    public static function reserve(string $channel, callable $prepare) : int
    {
        if (!in_array($channel,['email','sms'],true)) { $prepare(); return 0; }
        $now=time(); $limit=(int)Settings::get()[$channel.'_per_minute'];
        $state=Store::decode(Store::table('setting')->where('id',self::STATE_ID)->value('data'));
        $recent=[];
        foreach (['email','sms'] as $key) {
            $recent[$key]=array_values(array_filter($state[$key] ?? [],static function ($stamp) use ($now) { return is_int($stamp) && $stamp>$now-60; }));
            sort($recent[$key],SORT_NUMERIC);
        }
        $count=count($recent[$channel]);
        // Lowering the limit must let enough reservations leave the window.
        if ($count>=$limit) { return $recent[$channel][$count-$limit]+60; }
        // SMS budget rejection never consumes sending capacity.
        if ($prepare()===false) { return -1; }
        $recent[$channel][]=$now;
        Store::table('setting')->extra('IGNORE')->insert(['id'=>self::STATE_ID,'data'=>'{}','updated_at'=>$now]);
        Store::table('setting')->where('id',self::STATE_ID)->update(['data'=>Store::json($recent),'updated_at'=>$now]);
        return 0;
    }
}
