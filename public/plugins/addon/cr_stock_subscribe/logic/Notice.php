<?php
namespace addon\cr_stock_subscribe\logic;

use think\facade\Db;

class Notice
{
    const EMAIL_PRODUCT_LIMIT = 10;
    private static $catalog=null;
    public static function catalog(callable $work) {
        if (self::$catalog!==null) { return $work(); }
        self::$catalog=[];
        try { return Settings::snapshot($work); } finally { self::$catalog=null; }
    }

    public static function actions() : array
    {
        return [
            'cr_stock_subscribe_single' => ['label'=>'补货订阅：单商品','sms'=>'您订阅的「@var(stock_product_name)」已补货，请登录会员中心查看。','subject'=>'您订阅的商品已补货','email'=>self::emailTemplate(false,false)],
            'cr_stock_subscribe_multiple' => ['label'=>'补货订阅：多商品合并','sms'=>'您订阅的@var(stock_product_count)款商品已补货，请登录会员中心查看。','subject'=>'您订阅的 {stock_product_count} 款商品已补货','email'=>self::emailTemplate(true,false)],
            'cr_stock_subscribe_delisted_single' => ['label'=>'补货订阅：商品下架','sms'=>'您订阅的「@var(stock_product_name)」已下架，请登录会员中心查看。','subject'=>'您订阅的商品已下架','email'=>self::emailTemplate(false,true)],
            'cr_stock_subscribe_delisted_multiple' => ['label'=>'补货订阅：多商品下架合并','sms'=>'您订阅的@var(stock_product_count)款商品已下架，请登录会员中心查看。','subject'=>'您订阅的 {stock_product_count} 款商品已下架','email'=>self::emailTemplate(true,true)],
        ];
    }
    private static function emailTemplate(bool $multiple, bool $delisted) : string
    {
        $title=$delisted ? '您关注的商品已下架' : '您关注的商品已补货';
        $lead=$multiple ? '您订阅的 {stock_product_count} 款商品' : '您订阅的商品';
        $lead.=$delisted ? '已隐藏或删除，目前无法购买。' : '已有库存，可以前往商品页面查看。';
        $note=$delisted ? '这是一条商品状态提醒，不代表已购服务发生变化。补货订阅仍会保留，您可以在会员中心管理或取消。' : '库存随时变化，本通知不预留库存，购买信息请以商品页面为准。';
        $color=$delisted ? '#475569' : '#2563eb';
        $time=$delisted ? '{stock_delist_time}' : '{stock_restock_time}';
        // Tables and inline styles keep the email readable without remote
        // styles or fonts. Only the configured system logo is loaded remotely.
        return '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $title . '</title></head>' .
            '<body style="margin:0;padding:0;background:#f4f6f8;color:#1e293b;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Microsoft YaHei,Arial,sans-serif;">' .
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;"><tr><td align="center" style="padding:20px 12px;">' .
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;table-layout:fixed;background:#ffffff;border:1px solid #e2e8f0;border-radius:3px;">' .
            '<tr><td style="padding:20px;border-bottom:1px solid #eef2f6;">{stock_system_logo_html}<div style="margin-top:10px;font-size:12px;line-height:18px;color:#64748b;">商品动态 / 补货订阅</div></td></tr>' .
            '<tr><td style="padding:20px;"><h1 style="margin:0 0 8px;font-size:20px;line-height:28px;font-weight:600;">' . $title . '</h1><p style="margin:0 0 16px;font-size:14px;line-height:22px;color:#475569;">' . $lead . '</p>' .
            '{stock_product_list_html}<p style="margin:16px 0;font-size:12px;line-height:20px;color:#64748b;">' . $note . '</p>' .
            '<table role="presentation" cellpadding="0" cellspacing="0"><tr><td bgcolor="' . $color . '" style="border-radius:3px;"><a href="{stock_notification_url}" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:10px 16px;font-size:14px;line-height:20px;font-weight:600;color:#ffffff;text-decoration:none;">查看本次通知</a></td></tr></table>' .
            '<p style="margin:16px 0 0;font-size:12px;line-height:18px;color:#64748b;">通知时间：' . $time . '</p></td></tr>' .
            '<tr><td style="padding:14px 20px;background:#f8fafc;border-top:1px solid #eef2f6;font-size:12px;line-height:18px;color:#64748b;">您收到此邮件，是因为您订阅了这些商品的补货通知。<br>可在会员中心修改通知渠道或取消订阅。</td></tr>' .
            '</table></td></tr></table></body></html>';
    }
    private static function legacyEmailHashes() : array
    {
        return [
            // Only exact shipped defaults may be replaced, including the
            // original plain restock templates and the first card templates.
            'cr_stock_subscribe_single'=>[
                hash('sha256','<p>您订阅的「{stock_product_name}」已补货。</p>{stock_product_list_html}<p>库存随时变化，请以购买页面为准。</p><p><a href="{stock_manage_url}">查看或管理补货订阅</a></p>'),
                'fde66b24d9b7401988b4ffd04f8eedf6ae86f4b362050175045bc402332eba98',
                '182ed49471b2700195531714206e1c858c4ce9f5bc58cac81cd3b91c4ea6e4e4',
            ],
            'cr_stock_subscribe_multiple'=>[
                hash('sha256','<p>您订阅的 {stock_product_count} 款商品已补货：</p>{stock_product_list_html}<p>库存随时变化，请以购买页面为准。</p><p><a href="{stock_manage_url}">查看或管理补货订阅</a></p>'),
                'a97a6a047f8af02f9443111279faebaa629aa559e90cf9df30b3bd2d3131e4bb',
                '4877214436328c5d37e776d1a8c03ec6c0c9304ba457f9dda2ffb2c78e72632c',
            ],
            'cr_stock_subscribe_delisted_single'=>['0eb401a0145e7d80dccd4b6faa802fb17d77c146e75c673004361f4938807ef1','3ac851d4699949ebe78a2888a01ec1724974e3591770ed7b07d34408633d87b4'],
            'cr_stock_subscribe_delisted_multiple'=>['cf622a76dfd87e3345eca7b22478eba10431704993996bcd6dd64c679fc8806b','140dea5a3ea53556ada56f0fba8adc77df2de86cc37783d35c182c0bec633a82'],
        ];
    }
    public static function seed() : void
    {
        $mail = Db::name('plugin')->where('module','mail')->where('status',1)->order('id')->value('name') ?: 'Smtp';
        $sms = Db::name('plugin')->where('module','sms')->where('status',1)->order('id')->value('name') ?: 'Idcsmart';
        foreach (self::actions() as $name => $a) {
            // Native SMS template creation may submit an application to the SMS
            // provider. Installation creates local drafts only, without network IO.
            $existing=Db::name('notice_setting')->where('name',$name)->find();
            if (!$existing) {
                $res = notice_action_create(['name'=>$name,'name_lang'=>$a['label'],'type'=>'other','email_name'=>$mail,
                    'email_template'=>['name'=>$a['label'],'title'=>$a['subject'],'content'=>$a['email']]]);
                if (($res['status'] ?? 0) !== 200) { throw new \RuntimeException('补货通知模板登记失败。'); }
            }
            Db::transaction(static function () use ($name,$a,$mail,$sms,$existing) {
                $setting=Db::name('notice_setting')->where('name',$name)->lock(true)->find(); $updates=[];
                $emailId=(int)$setting['email_template'];
                if (!$emailId || !Db::name('email_template')->where('id',$emailId)->count()) {
                    $emailId=(int)Db::name('email_template')->where('notice_setting_name',$name)->order('id')->value('id');
                    if (!$emailId) {
                        $r=(new \app\common\model\EmailTemplateModel())->createEmailTemplate(['name'=>$a['label'],'subject'=>$a['subject'],'message'=>$a['email']]);
                        if (($r['status'] ?? 0)!==200) { throw new \RuntimeException('邮件模板登记失败。'); }
                        $emailId=(int)$r['id'];
                    }
                    $updates['email_template']=$emailId;
                }
                if (!$existing || isset($updates['email_template'])) { Db::name('email_template')->where('id',$emailId)->update(['notice_setting_name'=>$name]); }
                // Upgrade only the exact original defaults. Merchant subjects,
                // bodies, native associations and provider settings stay intact.
                $template=Db::name('email_template')->where('id',$emailId)->lock(true)->find();
                if ($template && $template['subject']===$a['subject'] && in_array(hash('sha256',$template['message']),self::legacyEmailHashes()[$name] ?? [],true)) {
                    Db::name('email_template')->where('id',$emailId)->update(['message'=>$a['email']]);
                }
                foreach ([0=>'sms',1=>'sms_global'] as $type=>$key) {
                    if ($setting[$key . '_template'] && Db::name('sms_template')->where('id',$setting[$key . '_template'])->count()) { continue; }
                    $provider=$setting[$key . '_name'] ?: $sms;
                    $id=(int)Db::name('sms_template')->where('notice_setting_name',$name)->where('type',$type)->where('sms_name',$provider)->order('id')->value('id');
                    if (!$id) { $id=Db::name('sms_template')->insertGetId(['sms_name'=>$provider,'type'=>$type,'title'=>$a['label'],'content'=>$a['sms'],'template_id'=>'','notes'=>'本地草稿，请在 V10 短信模板管理中关联或提交审核。','status'=>0,'notice_setting_name'=>$name,'create_time'=>time(),'update_time'=>time()]); }
                    $updates[$key . '_name']=$provider; $updates[$key . '_template']=$id;
                }
                if ($updates) { Db::name('notice_setting')->where('name',$name)->update($updates); }
            });
        }
    }
    public static function parameters() : array
    {
        return ['stock_product_short_name'=>'短信短商品名称（首件，最多 20 个字符，超长以省略号结尾）','stock_product_name'=>'商品名称（多商品时为首件）','stock_product_count'=>'本次通知的商品总数（不受邮件展示上限影响）','stock_product_list'=>'完整商品清单（纯文本）','stock_product_list_html'=>'邮件商品清单（每项一行，最多 10 项，超出可打开本次完整清单）','stock_product_display_count'=>'邮件展示的商品数量（最多 10 项）','stock_product_remaining_count'=>'邮件未展示的商品数量','stock_system_logo_html'=>'系统 Logo（邮件 HTML；未配置时显示站点名称）','stock_restock_time'=>'补货时间（仅补货通知）','stock_delist_time'=>'下架时间（仅下架通知）','stock_event_type'=>'事件类型（restock / delisted）','stock_event_time'=>'本次事件时间','stock_purchase_url'=>'购买链接（多商品时进入订阅页）','stock_manage_url'=>'订阅管理链接','stock_notification_url'=>'本次通知详情链接（登录后仅本人可查看）'];
    }
    public static function action(int $count, string $eventType = 'restock') : string { return 'cr_stock_subscribe_' . ($eventType==='delisted' ? 'delisted_' : '') . ($count>1 ? 'multiple' : 'single'); }
    public static function configurationReason(string $channel, int $count, string $eventType, bool $international = false) : string
    {
        return self::templateReason($channel,['phone_code'=>$international ? '1' : '86'],$count,$eventType);
    }
    public static function client(int $id) : array { return Db::name('client')->where('id',$id)->find() ?: []; }
    public static function receiver(array $client, string $channel) : string { return $channel === 'email' ? (string)($client['email'] ?? '') : (($client['phone_code'] ?? '') . ':' . ($client['phone'] ?? '')); }
    public static function hash(array $client, string $channel) : string { return hash('sha256', self::receiver($client,$channel)); }
    public static function template(string $channel, string $action, array $client = []) : array
    {
        $cacheKey=$channel.':'.$action.':'.(in_array(ltrim((string)($client['phone_code'] ?? '86'),'+'),['','86'],true)?'cn':'global');
        if(self::$catalog!==null && isset(self::$catalog[$cacheKey])) { return self::$catalog[$cacheKey]; }
        $setting = Db::name('notice_setting')->where('name',$action)->find() ?: [];
        $key = $channel === 'sms' && !in_array(ltrim((string)($client['phone_code'] ?? '86'),'+'),['','86'],true) ? 'sms_global' : $channel;
        $id = (int)($setting[$key . '_template'] ?? 0);
        $template = $id ? (Db::name($channel . '_template')->where('id',$id)->find() ?: []) : [];
        $data=['setting'=>$setting,'template'=>$template,'provider'=>(string)($setting[$key . '_name'] ?? ''),'key'=>$key];
        if(self::$catalog!==null) { self::$catalog[$cacheKey]=$data; }
        return $data;
    }
    public static function usable(string $channel, array $client, int $count = 1, string $eventType = 'restock') : string
    {
        $settings = Settings::get();
        if (empty($settings[$channel])) { return '商家未开放此通知渠道'; }
        if (!$client || (int)($client['status'] ?? 0) !== 1) { return '账户不可用'; }
        if ($channel === 'webhook') { return ''; }
        // Same native ClientModel::clientNotice rule, using the already read
        // account snapshot. Actual send re-reads the account before claiming.
        $receive=isset($client['notice_open'],$client['notice_method']) ? ((int)$client['notice_open']===1 && in_array($client['notice_method'],['all',$channel],true)) : client_notice(['type'=>$channel,'client_id'=>(int)$client['id']]);
        if (!$receive) { return '账户接收设置未开放此通知渠道'; }
        if (empty($client['receive_' . $channel])) { return '账户已关闭此类通知'; }
        if ($channel === 'email' && !filter_var($client['email'] ?? '',FILTER_VALIDATE_EMAIL)) { return '请先绑定邮箱'; }
        if ($channel === 'sms' && !preg_match('/\A[0-9]{4,20}\z/',(string)($client['phone'] ?? ''))) { return '请先绑定手机号'; }
        if ($channel === 'sms' && !empty($client['phone_code']) && !(new \app\common\model\CountryModel())->checkPhoneCode(ltrim((string)$client['phone_code'],'+'))) { return '账户手机号区号不可用'; }
        // Before merging, either shape can be usable. The exact action is
        // checked again against the final product list before a delivery exists.
        if ($count===0) {
            $single=self::templateReason($channel,$client,1,$eventType);
            $multiple=self::templateReason($channel,$client,2,$eventType);
            if ($single==='' || $multiple==='') { return ''; }
            return self::recoverable($single) ? $single : (self::recoverable($multiple) ? $multiple : $single);
        }
        return self::templateReason($channel,$client,$count,$eventType);
    }
    private static function templateReason(string $channel, array $client, int $count, string $eventType) : string
    {
        $data = self::template($channel,self::action($count,$eventType),$client);
        if (empty($data['setting'][$channel . '_enable'])) { return 'V10 通知设置未开启此渠道'; }
        if (!$data['template'] || !$data['provider']) { return 'V10 尚未配置通知接口或模板'; }
        $providerKey='provider:'.$channel.':'.$data['provider'];
        $provider=self::$catalog!==null && array_key_exists($providerKey,self::$catalog) ? self::$catalog[$providerKey] : Db::name('plugin')->where('module',$channel === 'email' ? 'mail' : 'sms')->where('name',$data['provider'])->where('status',1)->count();
        if(self::$catalog!==null) { self::$catalog[$providerKey]=$provider; }
        if (!$provider) { return 'V10 发送接口未启用'; }
        if ($channel === 'sms' && (int)($data['template']['status'] ?? 0) !== 2) { return '短信模板尚未审核通过'; }
        if ($channel === 'sms' && $data['template']['sms_name']!==$data['provider']) { return '短信模板与发送接口不一致'; }
        return '';
    }
    public static function recoverable(string $reason) : bool
    {
        return in_array($reason,['V10 尚未配置通知接口或模板','V10 发送接口未启用','短信模板尚未审核通过','短信模板与发送接口不一致'],true);
    }
    public static function variables(array $products, int $time, string $eventType = 'restock', string $event = '') : array
    {
        $settings=Settings::get(); $base=Store::baseUrl(); $manage=$base . Store::memberPath(); $text=[]; $html=[]; $down=$eventType==='delisted';
        $notification=$manage . (preg_match('/\A[a-f0-9]{32}\z/',$event) ? '?notification='.$event : '');
        // V10's mail adapter decodes one layer of HTML entities before sending.
        $safe=static function ($text) { return htmlspecialchars(htmlspecialchars((string)$text,ENT_QUOTES,'UTF-8'),ENT_QUOTES,'UTF-8'); };
        foreach ($products as $p) {
            $url=$down ? '' : $base . '/cart/goods.htm?id=' . (int)$p['id'];
            $suffix=!$down && $settings['show_quantity'] ? '库存 ' . (int)$p['qty'] : '';
            if (!$down && $settings['show_price']) { $suffix.=($suffix ? ' · ' : '') . '当前展示价格 ' . (string)$p['price']; }
            $text[]=$p['name'] . ($down ? '（已下架）' : ($suffix ? '（' . $suffix . '）' : '') . ' ' . $url);
            if (count($html)>=self::EMAIL_PRODUCT_LIMIT) { continue; }
            $name=$safe(mb_strimwidth((string)$p['name'],0,100,'…','UTF-8'));
            if (!$down) { $name='<a href="' . $safe($url) . '" target="_blank" rel="noopener noreferrer" style="color:#2563eb;text-decoration:none;">' . $name . '</a>'; }
            $details=$down ? '已下架' : str_replace('当前展示价格','价格',$suffix);
            $width=$down ? 48 : ($settings['show_quantity'] && $settings['show_price'] ? 148 : ($settings['show_price'] ? 100 : 72));
            $last=count($html)===min(count($products),self::EMAIL_PRODUCT_LIMIT)-1;
            $border=$last ? '' : 'border-bottom:1px solid #e2e8f0;';
            $html[]='<tr data-stock-product="' . (int)$p['id'] . '"><td style="padding:7px 12px;' . $border . '"><div title="' . $safe($p['name']) . '" style="font-size:14px;line-height:22px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' . $name . '</div></td>' .
                ($details!=='' ? '<td width="' . $width . '" align="right" style="width:' . $width . 'px;padding:7px 12px 7px 0;' . $border . 'font-size:12px;line-height:22px;color:#64748b;white-space:nowrap;"><div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' . $safe($details) . '</div></td>' : '') . '</tr>';
        }
        $shown=count($html); $remaining=max(0,count($products)-$shown);
        $list='<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;table-layout:fixed;border-collapse:separate;border-spacing:0;border:1px solid #e2e8f0;background:#f8fafc;">' . implode('',$html) . '</table>';
        if ($remaining>0) { $list.='<p style="margin:8px 0 0;font-size:12px;line-height:20px;color:#64748b;">本次共 ' . count($products) . ' 款，邮件仅展示前 ' . $shown . ' 款，另有 ' . $remaining . ' 款。<a href="' . $safe($notification) . '" target="_blank" rel="noopener noreferrer" style="color:#2563eb;text-decoration:underline;">查看本次完整清单</a>。</p>'; }
        $logo=(string)configuration('system_logo'); $siteName=$safe(configuration('website_name'));
        // V10 stores the system logo filename under upload/common/default.
        // Build the absolute URL from website_url, never the cron/HTTP host.
        $brand='<span style="font-size:16px;line-height:24px;font-weight:600;color:#1e293b;">' . $siteName . '</span>';
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\.(?:png|jpe?g|gif|webp|svg)\z/i',$logo)) {
            // The native default is a transparent white logo. A compact medium
            // blue backing keeps both light and dark system marks visible.
            $brand='<table role="presentation" cellpadding="0" cellspacing="0"><tr><td bgcolor="#4b78b2" style="padding:8px 10px;background:#4b78b2;border-radius:2px;"><a href="' . $safe($base) . '" target="_blank" rel="noopener noreferrer" style="display:inline-block;text-decoration:none;"><img src="' . $safe($base . '/upload/common/default/' . rawurlencode($logo)) . '" alt="' . $siteName . '" height="32" style="display:block;width:auto;height:32px;max-width:180px;max-height:32px;border:0;color:#ffffff;font-size:13px;"></a></td></tr></table>';
        } else {
            $brand='<a href="' . $safe($base) . '" target="_blank" rel="noopener noreferrer" style="display:inline-block;color:#1e293b;text-decoration:none;">' . $brand . '</a>';
        }
        return ['stock_product_short_name'=>mb_strlen((string)($products[0]['name'] ?? ''))>20 ? mb_substr((string)$products[0]['name'],0,19).'…' : (string)($products[0]['name'] ?? ''),'stock_product_name'=>(string)($products[0]['name'] ?? ''),'stock_product_count'=>count($products),'stock_product_list'=>implode("\n",$text),
            'stock_product_list_html'=>$list,'stock_product_display_count'=>$shown,'stock_product_remaining_count'=>$remaining,'stock_system_logo_html'=>$brand,
            'stock_restock_time'=>$down ? '' : date('Y-m-d H:i:s',$time),'stock_delist_time'=>$down ? date('Y-m-d H:i:s',$time) : '',
            'stock_event_type'=>$eventType,'stock_event_time'=>date('Y-m-d H:i:s',$time),
            'stock_purchase_url'=>!$down && count($products)===1 ? ($base . '/cart/goods.htm?id=' . (int)$products[0]['id']) : $manage,'stock_manage_url'=>$manage,'stock_notification_url'=>$notification];
    }
    public static function smsEstimate(array $products, array $client, int $time, string $eventType = 'restock') : int
    {
        $t = self::template('sms',self::action(count($products),$eventType),$client);
        // A future delivery has a fixed 32-character event id. Include that
        // suffix when a merchant opts into notification links in an SMS.
        $vars = (new \app\common\logic\NoticeTemplateVarLogic())->format(['client_id'=>(int)$client['id'],'template_param'=>self::variables($products,$time,$eventType,str_repeat('0',32))]);
        $content = (string)($t['template']['content'] ?? '');
        $content = preg_replace_callback('/@var\(([a-zA-Z0-9_]+)\)|\{([a-zA-Z0-9_]+)\}/',static function ($m) use ($vars) { return (string)($vars[$m[1] ?: ($m[2] ?? '')] ?? ''); },$content);
        // Include a conservative signature allowance. Provider billing remains authoritative.
        $length = (int)(strlen(mb_convert_encoding($content,'UTF-16BE','UTF-8')) / 2) + (int)Settings::get()['sms_signature_length'];
        return $length <= 70 ? 1 : (int)ceil($length / 67);
    }
    public function send(string $channel, array $client, array $products, int $created, string $eventType = 'restock', string $event = '') : array
    {
        $vars = self::variables($products,$created,$eventType,$event);
        $param = ['name'=>self::action(count($products),$eventType),'client_id'=>(int)$client['id'],'email'=>(string)$client['email'],'phone'=>(string)$client['phone'],'phone_code'=>(string)$client['phone_code'],'template_param'=>$vars];
        // Deliberately use V10's actual template senders inside our owned V10 task.
        // Calling system_notice once per subscriber would also trigger unrelated group webhooks.
        $result = $channel === 'email' ? (new EmailNotice())->send($param) : (new \app\common\logic\SmsLogic())->send($param);
        if ((int)($result['status'] ?? 0) === 200) { return ['status'=>'accepted']; }
        $message = (string)($result['msg'] ?? '');
        // V10 adapters may collapse transport failures into an ordinary error.
        // Only an explicit rejection or a known pre-send failure is retryable;
        // an unfamiliar error is not proof that the provider rejected a request.
        $rejected=$channel==='email'
            ? preg_match('/could not authenticate|invalid address|following recipients failed|SMTP connect\(\) failed|could not connect to SMTP host|configuration item cannot be empty|SMTP code\s*:\s*[45][0-9]{2}\b/i',$message)
            : preg_match('/余额不足|金额不足|签名(?:错误|不正确|不存在|未通过审核|不能为空)|模板(?:错误|不存在|未通过审核)|模板ID错误|手机号(?:错误|不正确|无效)|号码(?:错误|无效)|黑名单|超出(?:发送|频率|次数)限制|invalid (?:recipient|phone|template)|insufficient (?:balance|credit)/i',$message);
        return ['status'=>$rejected ? 'failed' : 'unknown','reason'=>'V10 发送接口：'.(Diagnostics::reason($message) ?: '未返回成功结果').'；请核对系统发送日志。','provider_code'=>Diagnostics::code($result['code'] ?? $result['status'] ?? '')];
    }
}
