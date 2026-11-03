<?php
namespace addon\cr_stock_subscribe\logic;

class Webhook
{
    private $deadline=0.0;
    public function send(array $endpoint, array $products, string $event, int $created, string $source, string $eventType = 'restock') : array
    {
        $this->deadline=microtime(true)+10;
        try {
            $url=Store::open($endpoint['url']); $secret=Store::open($endpoint['secret']);
            $target=$this->resolve($url,$endpoint['kind']); $settings=Settings::get(); $public=[]; $down=$eventType==='delisted'; $lines=['【补货订阅】' . count($products) . ($down ? '款商品已下架' : '款商品已补货')];
            foreach ($products as $p) {
                $entry=['product_id'=>(int)$p['id'],'name'=>$p['name']];
                if (!$down) {
                    $entry['purchase_url']=Store::baseUrl() . '/cart/goods.htm?id=' . (int)$p['id'];
                    if ($settings['show_quantity']) { $entry['quantity']=(int)$p['qty']; }
                    if ($settings['show_price']) { $entry['display_price']=(string)$p['price']; }
                }
                $public[]=$entry;
                $lines[]=mb_strcut($p['name'],0,400,'UTF-8') . ($down ? '（已下架）' : (($settings['show_quantity'] ? '（库存 ' . (int)$p['qty'] . '）' : '') . ($settings['show_price'] ? ' 展示价格 ' . (string)$p['price'] . '（以下单为准）' : '') . "\n" . $entry['purchase_url']));
            }
            $manage=Store::baseUrl() . Store::memberPath();
            $lines[]=$down ? '商品已隐藏或删除，补货订阅仍保留，可在会员中心管理。' . "\n" . $manage : '库存随时变化，请以购买页面为准。'; $message=implode("\n",$lines);
            $payload=['event'=>$down ? 'stock.delisted' : 'stock.restocked','event_id'=>$event,'source'=>$source,($down ? 'delisted_at' : 'restocked_at')=>date(DATE_ATOM,$created),'products'=>$public];
            if ($down) { $payload['manage_url']=$manage; }
            if ($endpoint['kind']==='wecom') { $payload=['msgtype'=>'text','text'=>['content'=>$message]]; }
            elseif ($endpoint['kind']==='dingtalk') {
                $payload=['msgtype'=>'text','text'=>['content'=>$message]];
                if ($secret !== '') { $timestamp=(string)(int)floor(microtime(true)*1000); $sign=base64_encode(hash_hmac('sha256',$timestamp . "\n" . $secret,$secret,true)); $url.=(strpos($url,'?')===false ? '?' : '&') . http_build_query(['timestamp'=>$timestamp,'sign'=>$sign]); }
            }
            $body=Store::json($payload); $timestamp=(string)time();
            $headers=['Content-Type: application/json','X-Stock-Event: ' . $event,'X-Stock-Timestamp: ' . $timestamp];
            if ($secret !== '' && $endpoint['kind']==='json') { $headers[]='X-Stock-Signature: sha256=' . hash_hmac('sha256',$timestamp . "\n" . $body,$secret); }
            if(microtime(true)>=$this->deadline) { return ['status'=>'failed','reason'=>'Webhook 发送前解析或准备超时，尚未请求接收端']; }
            [$errno,$code,$reply,$oversize]=$this->request($url,$body,$headers,$target);
            if ($errno || $oversize) {
                // Only failures known to precede the HTTP request are safe to
                // retry. A truncated reply or interrupted upload may follow an
                // accepted request, even when cURL reports a transport error.
                $beforeRequest=[CURLE_UNSUPPORTED_PROTOCOL,CURLE_URL_MALFORMAT,CURLE_COULDNT_RESOLVE_HOST,CURLE_COULDNT_CONNECT,CURLE_SSL_CONNECT_ERROR,CURLE_SSL_CACERT,CURLE_SSL_CACERT_BADFILE];
                return ['status'=>!$oversize && in_array($errno,$beforeRequest,true) ? 'failed' : 'unknown','reason'=>'Webhook 请求未确认成功','http_code'=>$code];
            }
            $accepted=$code>=200 && $code<300;
            if ($endpoint['kind']!=='json') {
                $decoded=json_decode($reply,true);
                $businessCode=is_array($decoded) ? ($decoded['errcode'] ?? null) : null;
                if ($accepted && (!is_int($businessCode) && !(is_string($businessCode) && preg_match('/\A-?[0-9]+\z/',$businessCode)))) {
                    return ['status'=>'unknown','reason'=>'Webhook 回包无法确认业务结果，请核对接收方记录','http_code'=>$code];
                }
                $accepted=$accepted && (string)$businessCode==='0';
            }
            return ['status'=>$accepted ? 'accepted' : 'failed','reason'=>$accepted ? '' : ('Webhook 接收方未返回成功结果'.(!empty($decoded['errmsg']) ? '：'.Diagnostics::reason((string)$decoded['errmsg']) : '')),'http_code'=>$code,'provider_code'=>Diagnostics::code($decoded['errcode'] ?? '')];
        } catch (\DomainException $e) { return ['status'=>'failed','reason'=>$e->getMessage()]; }
        catch (\Throwable $e) { return ['status'=>'unknown','reason'=>'Webhook 执行结果待核对']; }
    }
    protected function resolve(string $url, string $kind) : array { return Endpoint::validate($url,$kind,true); }
    protected function request(string $url, string $body, array $headers, array $target) : array
    {
        // Try another validated address only when no HTTP request could have
        // reached the receiver. All connection attempts share the ten-second
        // deadline; an ambiguous upload or reply must never fail over.
        $deadline=$this->deadline ?: microtime(true)+10; $result=[CURLE_COULDNT_CONNECT,0,'',false];
        foreach (array_slice($target['ips'],0,5) as $ip) {
            $remaining=(int)floor(($deadline-microtime(true))*1000);
            if ($remaining<=0) { break; }
            $attempt=$target; $attempt['ips']=[$ip]; $attempt['timeout_ms']=$remaining;
            $result=$this->requestOnce($url,$body,$headers,$attempt);
            if ($result[1] || $result[3] || !in_array($result[0],[CURLE_COULDNT_CONNECT,CURLE_SSL_CONNECT_ERROR,CURLE_SSL_CACERT],true)) { break; }
        }
        return $result;
    }
    protected function requestOnce(string $url, string $body, array $headers, array $target) : array
    {
        $reply=''; $oversize=false; $curl=curl_init(); $ip=$target['ips'][0];
        $timeout=(int)($target['timeout_ms'] ?? 10000);
        $options=[CURLOPT_URL=>$url,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_CONNECTTIMEOUT_MS=>min(5000,$timeout),CURLOPT_TIMEOUT_MS=>$timeout,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_PROXY=>'',CURLOPT_USERAGENT=>'CrStockSubscribe/' . Store::VERSION,
            CURLOPT_WRITEFUNCTION=>static function ($handle,$chunk) use (&$reply,&$oversize) { if (strlen($reply)+strlen($chunk)>16384) { $oversize=true; return 0; } $reply.=$chunk; return strlen($chunk); }];
        // Numeric hosts already fix the destination, including IPv6 literals.
        if (!filter_var($target['host'],FILTER_VALIDATE_IP)) { $options[CURLOPT_RESOLVE]=[$target['host'] . ':' . $target['port'] . ':' . (strpos($ip,':')!==false ? '[' . $ip . ']' : $ip)]; }
        curl_setopt_array($curl,$options);
        curl_exec($curl); $errno=curl_errno($curl); $code=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
        return [$errno,$code,$reply,$oversize];
    }
}
