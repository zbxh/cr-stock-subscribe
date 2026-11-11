<?php
namespace addon\cr_stock_subscribe\logic;

use think\facade\Db;

final class Settings
{
    private static $snapshot=null;
    public static function snapshot(callable $work) {
        if (self::$snapshot!==null) { return $work(); }
        self::$snapshot=self::get();
        try { return $work(); } finally { self::$snapshot=null; }
    }
    public static function defaults() : array
    {
        return ['enabled' => 1, 'email' => 1, 'sms' => 0, 'webhook' => 1, 'show_quantity' => 1, 'show_price' => 0,
            'max_subscriptions' => 20, 'valid_days' => 90, 'check_seconds' => 60, 'merge_seconds' => 60,
            'cooldown_seconds' => 3600, 'sms_product_seconds' => 86400, 'sms_client_daily' => 3, 'sms_site_daily' => 100,
            'email_per_minute'=>20, 'sms_per_minute'=>10,
            'sms_signature_length'=>10, 'history_archive_days'=>0, 'notification_seconds' => 1800, 'webhook_attempts' => 3];
    }
    public static function get() : array { if(self::$snapshot!==null) { return self::$snapshot; } return array_replace(self::defaults(), Store::decode(Store::table('setting')->where('id', 1)->value('data'))); }
    public static function revision(array $settings) : string { return hash('sha256',Store::json($settings)); }
    public static function save(array $input, int $admin, ?string $expectedRevision = null) : array
    {
        $bounds = ['email_per_minute'=>[1,1000], 'sms_per_minute'=>[1,1000], 'sms_signature_length'=>[0,100], 'history_archive_days'=>[0,3650], 'max_subscriptions' => [1,200], 'valid_days' => [1,730], 'check_seconds' => [60,3600], 'merge_seconds' => [0,300], 'cooldown_seconds' => [60,86400], 'sms_product_seconds' => [3600,604800], 'sms_client_daily' => [0,10000], 'sms_site_daily' => [0,1000000], 'notification_seconds' => [60,86400], 'webhook_attempts' => [1,3]];
        return Store::transaction(function () use ($input, $admin, $bounds, $expectedRevision) {
            $row = Store::table('setting')->where('id', 1)->lock(true)->find(); $old = self::get(); $next = $old;
            if ($expectedRevision!==null && !hash_equals(self::revision($old),$expectedRevision)) { throw new \DomainException('设置已在其他页面更新。当前草稿已保留，请重新读取最新设置后再修改保存。'); }
            foreach (self::defaults() as $key => $default) {
                if (!array_key_exists($key, $input)) { continue; }
                [$min,$max] = $bounds[$key] ?? [0,1];
                $next[$key] = Store::number($input[$key], $min, $max, self::labels()[$key] ?? $key);
            }
            if ($next['history_archive_days']>0 && $next['history_archive_days']<30) { throw new \DomainException('历史归档至少保留最近 30 天；0 表示关闭自动归档。'); }
            if ($next['notification_seconds'] <= $next['merge_seconds']) { throw new \DomainException('通知有效期须大于合并窗口；建议另预留 180 秒供分钟任务和发送队列处理。'); }
            Store::table('setting')->where('id', 1)->update(['data' => Store::json($next), 'updated_at' => time()]);
            if ($old['enabled'] !== $next['enabled']) {
                Engine::stop('功能开关已变更');
            }
            foreach (['email','sms','webhook'] as $channel) {
                if (!$next[$channel]) { Engine::cancelChannel($channel, '后台已关闭此渠道'); }
            }
            Store::audit('settings_saved', ['changes' => array_keys(array_diff_assoc($next, $old))], $admin);
            return $next;
        });
    }
    public static function labels() : array
    {
        return ['email_per_minute'=>'每分钟邮件上限','sms_per_minute'=>'每分钟短信提交上限','sms_signature_length'=>'短信签名预留字符','history_archive_days'=>'历史通知归档天数','max_subscriptions'=>'每人有效订阅上限','valid_days'=>'订阅有效天数','check_seconds'=>'检查间隔（秒）','merge_seconds'=>'合并窗口（秒）','cooldown_seconds'=>'同商品提醒冷却（秒）','sms_product_seconds'=>'同商品短信间隔（秒）','sms_client_daily'=>'每人每日短信额度','sms_site_daily'=>'全站每日短信额度','notification_seconds'=>'通知有效期（秒）','webhook_attempts'=>'Webhook 最大尝试次数'];
    }
}
