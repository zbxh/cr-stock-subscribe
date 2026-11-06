<?php
namespace addon\cr_stock_subscribe\logic;

use think\facade\Db;

final class Store
{
    const VERSION = '0.1.2';
    const PREFIX = 'addon_cr_stock_subscribe_';

    public static function table($name) { return Db::name(self::PREFIX . $name); }
    // All short plugin mutations take this lock first. No network request holds it.
    public static function transaction(callable $work)
    {
        return Db::transaction(static function () use ($work) {
            self::table('setting')->where('id',1)->lock(true)->find();
            return $work();
        });
    }
    public static function json($value) : string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    public static function decode($value) : array { $v = is_array($value) ? $value : json_decode((string)$value, true); return is_array($v) ? $v : []; }
    public static function ids($value, $max = 200) : array
    {
        if (!is_array($value)) { $value = explode(',', (string)$value); }
        $ids = [];
        foreach ($value as $id) {
            if (!is_scalar($id) || !preg_match('/\A[1-9][0-9]{0,9}\z/', (string)$id) || (int)$id > 2147483647) { throw new \DomainException('商品或记录编号不正确。'); }
            $ids[(int)$id] = (int)$id;
        }
        if (count($ids) > $max) { throw new \DomainException('一次选择的数量过多，请分批操作。'); }
        sort($ids); return array_values($ids);
    }
    public static function number($value, $min, $max, $label) : int
    {
        if (!is_scalar($value) || !preg_match('/\A[0-9]+\z/', (string)$value) || (float)$value < $min || (float)$value > $max) { throw new \DomainException($label . '应为 ' . $min . ' 至 ' . $max . ' 的整数。'); }
        return (int)$value;
    }
    public static function page(array $input) : array { return [max(1, min(100000, (int)($input['page'] ?? 1))), max(1, min(100, (int)($input['limit'] ?? 20)))]; }
    public static function active() : bool
    {
        return (bool)Db::name('plugin')->where('name', 'CrStockSubscribe')->where('module', 'addon')->where('status', 1)->count() && !empty(Settings::get()['enabled']);
    }
    public static function requireActive() : void { if (!self::active()) { throw new \DomainException('补货订阅暂未开放。'); } }
    public static function audit($action, array $detail = [], $actor = 0, $client = 0) : void
    {
        self::table('audit')->insert(['action' => $action, 'actor_id' => (int)$actor, 'client_id' => (int)$client, 'detail' => self::json($detail), 'created_at' => time()]);
    }
    public static function mask($value) : string
    {
        $s = (string)$value;
        if (strpos($s, '@') !== false) { [$name, $host] = explode('@', $s, 2); return mb_substr($name, 0, 1) . '***@' . $host; }
        return strlen($s) > 4 ? '***' . substr($s, -4) : '***';
    }
    public static function seal($plain) : string { return Keyring::seal((string)$plain); }
    public static function open($encoded) : string { return Keyring::open((string)$encoded); }
    public static function sign(string $text) : string { return hash_hmac('sha256',$text,Keyring::key()); }
    public static function baseUrl() : string
    {
        $url = rtrim((string)configuration('website_url'), '/');
        $parts = parse_url($url);
        if (!$parts || !filter_var($url,FILTER_VALIDATE_URL) || strlen($url)>512 || !in_array($parts['scheme'] ?? '',['http','https'],true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f\\\\]/',$url)) { throw new \DomainException('请先在 V10 系统设置中配置正确的网站地址。'); }
        return $url;
    }
    public static function memberPath() : string
    {
        $id = (int)Db::name('plugin')->where('name', 'CrStockSubscribe')->where('module', 'addon')->value('id');
        return '/plugin/' . $id . '/stock_subscribe.htm';
    }
    public static function response(callable $fn)
    {
        try { return json(['status' => 200, 'msg' => '操作成功', 'data' => $fn()]); }
        catch (\DomainException $e) { return json(['status' => 400, 'msg' => $e->getMessage()]); }
        catch (\Throwable $e) { Diagnostics::fault('request',0,$e); return json(['status' => 500, 'msg' => '操作暂未完成，请稍后重试或联系管理员。']); }
    }
}
