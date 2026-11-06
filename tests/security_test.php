<?php
// Standalone security checks; no V10, site configuration or database is loaded.
$plugin=dirname(__DIR__).'/public/plugins/addon/cr_stock_subscribe';
foreach (['Keyring','Store','Diagnostics','Endpoint','Webhook'] as $name) { require $plugin.'/logic/'.$name.'.php'; }
use addon\cr_stock_subscribe\logic\{Keyring,Store,Diagnostics,Endpoint,Webhook};
$root=sys_get_temp_dir().'/stock-security-'.bin2hex(random_bytes(8));
mkdir($root,0700); mkdir($root.'/public',0700); mkdir($root.'/private',0700);
define('IDCSMART_ROOT',$root.'/'); define('WEB_ROOT',$root.'/public/');
$path=$root.'/private/master.key'; $key=random_bytes(32);
file_put_contents($path,base64_encode($key)."\n");chmod($path,0600);
file_put_contents($path.'.lock','');chmod($path.'.lock',0600);
putenv('CR_STOCK_SUBSCRIBE_KEY_FILE='.$path);
$count=0;
function check($name,$ok) { global $count;if(!$ok)throw new RuntimeException($name);$count++;echo 'PASS '.$name."\n"; }
function rejects($name,callable $fn) { try{$fn();}catch(Throwable $e){check($name,true);return;}check($name,false); }
try {
    $plain='https://hooks.example.invalid/receive?token=synthetic-test-only';
    $first=Store::seal($plain);$second=Store::seal($plain);
    check('private file key supports encrypted round trip',Store::open($first)===$plain);
    check('ciphertext uses a fresh nonce',$first!==$second);
    check('empty signing secret round trip',Store::open(Store::seal(''))==='');
    unlink($path.'.lock');
    check('restoring a key alone safely recreates its empty lock',Store::open($first)===$plain);
    check('ciphertext does not contain the plaintext',strpos($first,$plain)===false);
    $tampered=base64_decode($first);$tampered[12]=chr(ord($tampered[12])^1);
    rejects('changed authentication tag is rejected',static function()use($tampered){Store::open(base64_encode($tampered));});
    rejects('truncated ciphertext is rejected',static function(){Store::open('AAAA');});
    file_put_contents($path,base64_encode(random_bytes(32))."\n");
    rejects('a different key cannot decrypt old data',static function()use($first){Store::open($first);});
    file_put_contents($path,base64_encode($key)."\n");
    chmod($path,0644);clearstatcache();
    rejects('world-readable key is rejected',static function(){Keyring::key();});
    chmod($path,0600);clearstatcache();
    unlink($path);
    rejects('a missing key is not regenerated at runtime',static function(){Keyring::key();});
    check('runtime did not create a replacement key',!file_exists($path));
    $result=(new Webhook())->send(['id'=>0,'url'=>$first,'secret'=>$second],[],str_repeat('0',32),time(),'test');
    check('pre-send configuration failure is not reported as an ambiguous delivery',$result['status']==='failed');
    file_put_contents($path,base64_encode($key)."\n");chmod($path,0600);
    putenv('CR_STOCK_SUBSCRIBE_KEY_FILE='.$root.'/public/master.key');
    rejects('public directory cannot hold a key',static function(){Keyring::preflight();});
    putenv('CR_STOCK_SUBSCRIBE_KEY_FILE=relative/master.key');
    rejects('relative key paths are rejected',static function(){Keyring::preflight();});
    putenv('CR_STOCK_SUBSCRIBE_KEY_FILE='.$path);
    $secret='synthetic-sensitive-value-123';
    foreach (['{"secret":"'.$secret.'"}','{"access_token": "'.$secret.'"}',"api_key='$secret'",'Authorization: Bearer '.$secret,'Bearer '.$secret,'Basic '.base64_encode($secret)] as $text) {
        check('credential formats are redacted',strpos(Diagnostics::reason($text),$secret)===false && strpos(Diagnostics::reason($text),base64_encode($secret))===false);
    }
    foreach (['127.0.0.1','10.0.0.1','172.16.0.1','192.168.1.1','169.254.169.254','100.64.0.1','198.18.0.1','::1','::ffff:127.0.0.1','2001:db8::1','2002:7f00:1::','64:ff9b::7f00:1'] as $ip) { check('private and special addresses are rejected: '.$ip,!Endpoint::publicIp($ip)); }
    check('public IPv4 remains usable',Endpoint::publicIp('8.8.8.8'));
    check('public IPv6 remains usable',Endpoint::publicIp('2606:4700:4700::1111'));
    foreach (['http://example.com/hook','https://127.0.0.1/hook','https://[::1]/hook','https://name:password@example.com/hook','https://example.com/hook#fragment',"https://example.com/\r\nHeader:value"] as $url) { rejects('unsafe Webhook URL is rejected',static function()use($url){Endpoint::validate($url,'json',false);}); }
    rejects('robot destinations use the official host',static function(){Endpoint::validate('https://example.com/robot/send','dingtalk',false);});
    check('disabled Webhook draft does not require DNS',Endpoint::validate('https://draft.invalid/hook','json',false)['ips']===[]);
    echo 'PASS '.$count." standalone security checks\n";
} finally {
    putenv('CR_STOCK_SUBSCRIBE_KEY_FILE');
    foreach (glob($root.'/private/*') as $file) { unlink($file); }
    rmdir($root.'/private');rmdir($root.'/public');rmdir($root);
}
