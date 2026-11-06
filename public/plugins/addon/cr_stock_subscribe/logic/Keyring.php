<?php
namespace addon\cr_stock_subscribe\logic;

use think\facade\Db;

/** Runtime keys live outside the public directory and the plugin package. */
final class Keyring
{
    private static function path() : string
    {
        $path=getenv('CR_STOCK_SUBSCRIBE_KEY_FILE') ?: rtrim(IDCSMART_ROOT,'/\\').'/data/cr_stock_subscribe/master.key';
        if (!preg_match('~\A(?:/|[a-zA-Z]:[/\\\\])~',$path) || preg_match('~(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~',$path)) { throw new \RuntimeException('插件密钥文件须使用非公开目录的绝对路径。'); }
        return $path;
    }
    private static function directory(bool $create) : string
    {
        $path=self::path(); $directory=dirname($path);
        if ($create && !is_dir($directory)) {
            $mask=umask(0077);
            try { if (!@mkdir($directory,0700,true) && !is_dir($directory)) { throw new \RuntimeException('无法创建插件私有密钥目录，请检查站点文件权限。'); } }
            finally { umask($mask); }
        }
        $real=realpath($directory);
        if ($real===false) { throw new \RuntimeException('插件私有密钥文件缺失，请恢复密钥备份；不会自动替换已有密钥。'); }
        foreach ([WEB_ROOT,dirname(__DIR__),$_SERVER['DOCUMENT_ROOT'] ?? ''] as $public) {
            $public=$public!=='' ? realpath($public) : false;
            if ($public!==false && ($real===$public || strpos($real,rtrim($public,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)===0)) { throw new \RuntimeException('插件密钥必须保存在 Web 目录和插件目录之外。'); }
        }
        return $real.DIRECTORY_SEPARATOR.basename($path);
    }
    private static function lock(string $path, bool $write)
    {
        if (is_link($path) || is_link($path.'.lock')) { throw new \RuntimeException('插件密钥不能使用符号链接文件。'); }
        $mask=umask(0077);
        try { $lock=@fopen($path.'.lock',$write || !is_file($path.'.lock') ? 'c+b' : 'rb'); }
        finally { umask($mask); }
        if (!$lock || !flock($lock,$write ? LOCK_EX : LOCK_SH)) { if (is_resource($lock)) { fclose($lock); } throw new \RuntimeException('无法读取或锁定插件私有密钥，请检查文件权限。'); }
        return $lock;
    }
    private static function read(string $path) : string
    {
        $mode=@fileperms($path);
        $encoded=@file_get_contents($path);
        $key=is_string($encoded) ? base64_decode(trim($encoded),true) : false;
        if ($mode===false || ($mode & 0077)!==0 || $key===false || strlen($key)!==32) { throw new \RuntimeException('插件私有密钥缺失、损坏或权限不正确，请恢复原密钥并设置权限为 0600。'); }
        return $key;
    }
    public static function key() : string
    {
        $path=self::directory(false); $lock=self::lock($path,false);
        try { return self::read($path); }
        finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    public static function preflight() : void
    {
        $path=self::directory(true);
        if (!is_writable(dirname($path))) { throw new \RuntimeException('插件私有密钥目录不可写，请先修正站点文件权限再升级。'); }
        if (file_exists($path)) { self::key(); }
    }
    private static function create(string $path) : string
    {
        $key=random_bytes(32); $temporary=$path.'.'.bin2hex(random_bytes(8)).'.tmp';
        $mask=umask(0077);
        try { $file=@fopen($temporary,'xb'); }
        finally { umask($mask); }
        if (!$file) { throw new \RuntimeException('无法写入插件私有密钥，请检查站点文件权限。'); }
        try {
            $encoded=base64_encode($key)."\n";
            if (fwrite($file,$encoded)!==strlen($encoded) || !fflush($file) || !@chmod($temporary,0600)) { throw new \RuntimeException('插件私有密钥保存失败。'); }
            fclose($file); $file=null;
            if (!@rename($temporary,$path)) { throw new \RuntimeException('插件私有密钥保存失败。'); }
        } finally { if (is_resource($file)) { fclose($file); } if (is_file($temporary)) { unlink($temporary); } }
        return $key;
    }
    public static function install() : void
    {
        $path=self::directory(true); $lock=null;
        // All mutations take the setting row first, including endpoint saves.
        // Hold the file lock through commit so readers cannot see half a migration.
        Db::startTrans();
        try {
            Store::table('setting')->where('id',1)->lock(true)->find();
            $lock=self::lock($path,true);
            $legacy=Store::table('setting')->where('id',3)->value('data');
            if (!file_exists($path) && $legacy===null && Store::table('endpoint')->count()) { throw new \RuntimeException('已有 Webhook 配置但私有密钥缺失，请恢复原密钥备份。'); }
            $key=file_exists($path) ? self::read($path) : self::create($path);
            if ($legacy!==null) {
                $old=base64_decode((string)$legacy,true);
                if ($old===false || strlen($old)!==32) { throw new \RuntimeException('旧版加密配置损坏，密钥迁移已停止。'); }
                $last=0; $count=0;
                do {
                    $rows=Store::table('endpoint')->where('id','>',$last)->order('id')->limit(200)->select()->toArray();
                    foreach ($rows as $row) {
                        $url=self::decrypt($row['url'],$old); $secret=self::decrypt($row['secret'],$old);
                        Store::table('endpoint')->where('id',$row['id'])->update(['url'=>self::encrypt($url,$key),'secret'=>self::encrypt($secret,$key)]);
                        $last=(int)$row['id']; $count++;
                    }
                } while (count($rows)===200);
                Store::table('setting')->where('id',3)->delete();
                Store::audit('encryption_key_migrated',['endpoints'=>$count]);
            }
            Db::commit();
        } catch (\Throwable $error) { Db::rollback(); throw $error; }
        finally { if (is_resource($lock)) { flock($lock,LOCK_UN); fclose($lock); } }
    }
    private static function encrypt(string $plain, string $key) : string
    {
        $iv=random_bytes(12); $tag='';
        $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);
        if ($cipher===false) { throw new \RuntimeException('通知地址加密失败。'); }
        return base64_encode($iv.$tag.$cipher);
    }
    private static function decrypt(string $encoded, string $key) : string
    {
        $raw=base64_decode($encoded,true);
        if ($raw===false || strlen($raw)<28) { throw new \RuntimeException('通知地址无法读取。'); }
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
        if ($plain===false) { throw new \RuntimeException('通知地址无法解密，请核对私有密钥备份。'); }
        return $plain;
    }
    public static function seal(string $plain) : string { return self::encrypt($plain,self::key()); }
    public static function open(string $encoded) : string { return self::decrypt($encoded,self::key()); }
}
