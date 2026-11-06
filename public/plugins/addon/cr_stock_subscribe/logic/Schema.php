<?php
namespace addon\cr_stock_subscribe\logic;

use think\facade\Db;

final class Schema
{
    public static function definitions() : array
    {
        return [
            'setting' => '`id` int NOT NULL,`data` longtext NOT NULL,`updated_at` int NOT NULL DEFAULT 0,PRIMARY KEY (`id`)',
            'product' => '`product_id` int NOT NULL,`enabled` tinyint NOT NULL DEFAULT 0,`known` tinyint NOT NULL DEFAULT 0,`qty` int NOT NULL DEFAULT 0,`cycle` bigint unsigned NOT NULL DEFAULT 1,`checked_at` int NOT NULL DEFAULT 0,`fresh_at` int NOT NULL DEFAULT 0,`reason` varchar(255) NOT NULL DEFAULT \'\',PRIMARY KEY (`product_id`)',
            'availability' => '`product_id` int NOT NULL,`state` varchar(16) NOT NULL DEFAULT \'unknown\',`name` varchar(255) NOT NULL DEFAULT \'\',`episode` bigint unsigned NOT NULL DEFAULT 0,`changed_at` int NOT NULL DEFAULT 0,`checked_at` int NOT NULL DEFAULT 0,PRIMARY KEY (`product_id`)',
            'subscription' => '`id` bigint unsigned NOT NULL AUTO_INCREMENT,`client_id` int NOT NULL,`product_id` int NOT NULL,`generation` int NOT NULL DEFAULT 1,`revision` int NOT NULL DEFAULT 1,`threshold` int NOT NULL DEFAULT 3,`mode` varchar(16) NOT NULL DEFAULT \'once\',`channels` text NOT NULL,`endpoint_ids` text NOT NULL,`status` varchar(16) NOT NULL DEFAULT \'active\',`expires_at` int NOT NULL,`last_notified_at` int NOT NULL DEFAULT 0,`created_at` int NOT NULL,`updated_at` int NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `client_product` (`client_id`,`product_id`),KEY `waiting_product` (`product_id`,`status`,`expires_at`)',
            'endpoint' => '`edit_revision` int NOT NULL DEFAULT 1,`admin_disabled` tinyint NOT NULL DEFAULT 0,`id` bigint unsigned NOT NULL AUTO_INCREMENT,`client_id` int NOT NULL DEFAULT 0,`scope` varchar(16) NOT NULL,`name` varchar(100) NOT NULL,`kind` varchar(16) NOT NULL,`url` text NOT NULL,`address_hash` char(64) NOT NULL,`secret` text NOT NULL,`enabled` tinyint NOT NULL DEFAULT 1,`revision` int NOT NULL DEFAULT 1,`threshold` int NOT NULL DEFAULT 3,`product_ids` text NOT NULL,`baseline` longtext NOT NULL,`last_test_at` int NOT NULL DEFAULT 0,`created_at` int NOT NULL,`updated_at` int NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `owner_address` (`client_id`,`scope`,`address_hash`),KEY `owner` (`client_id`,`scope`,`enabled`)',
            'item' => '`wait_code` varchar(24) NOT NULL DEFAULT \'\',`send_attempts` int NOT NULL DEFAULT 0,`attempted_at` int NOT NULL DEFAULT 0,`event_type` varchar(16) NOT NULL DEFAULT \'restock\',`id` bigint unsigned NOT NULL AUTO_INCREMENT,`identity_key` char(64) NOT NULL,`client_id` int NOT NULL DEFAULT 0,`subscription_id` bigint unsigned NOT NULL DEFAULT 0,`generation` int NOT NULL DEFAULT 0,`subscription_revision` int NOT NULL DEFAULT 0,`product_id` int NOT NULL,`cycle` bigint unsigned NOT NULL,`channel` varchar(16) NOT NULL,`endpoint_id` bigint unsigned NOT NULL DEFAULT 0,`endpoint_revision` int NOT NULL DEFAULT 0,`receiver_hash` char(64) NOT NULL,`threshold` int NOT NULL,`source` varchar(16) NOT NULL,`batch_key` varchar(64) NOT NULL,`delivery_id` bigint unsigned NOT NULL DEFAULT 0,`status` varchar(16) NOT NULL DEFAULT \'pending\',`reason` varchar(255) NOT NULL DEFAULT \'\',`due_at` int NOT NULL,`expires_at` int NOT NULL,`created_at` int NOT NULL,`accepted_at` int NOT NULL DEFAULT 0,PRIMARY KEY (`id`),UNIQUE KEY `logical_reminder` (`identity_key`),KEY `ready` (`status`,`due_at`),KEY `delivery_items` (`delivery_id`),KEY `client_product_time` (`client_id`,`product_id`,`accepted_at`)',
            'delivery' => '`archived_at` int NOT NULL DEFAULT 0,`event_type` varchar(16) NOT NULL DEFAULT \'restock\',`id` bigint unsigned NOT NULL AUTO_INCREMENT,`event_id` char(32) NOT NULL,`client_id` int NOT NULL DEFAULT 0,`channel` varchar(16) NOT NULL,`endpoint_id` bigint unsigned NOT NULL DEFAULT 0,`receiver_hash` char(64) NOT NULL,`receiver_hint` varchar(512) NOT NULL DEFAULT \'\',`endpoint_name` varchar(100) NOT NULL DEFAULT \'\',`status` varchar(16) NOT NULL DEFAULT \'pending\',`reason` varchar(255) NOT NULL DEFAULT \'\',`task_id` bigint unsigned NOT NULL DEFAULT 0,`attempts` int NOT NULL DEFAULT 0,`claim` char(32) NOT NULL DEFAULT \'\',`lease_until` int NOT NULL DEFAULT 0,`next_at` int NOT NULL DEFAULT 0,`expires_at` int NOT NULL,`sms_units` int NOT NULL DEFAULT 0,`snapshot` longtext NOT NULL,`created_at` int NOT NULL,`updated_at` int NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `event_id` (`event_id`),KEY `pending` (`status`,`next_at`),KEY `owner` (`client_id`,`created_at`)',
            'sms_budget' => '`day` char(10) NOT NULL,`client_id` int NOT NULL,`reserved` int NOT NULL DEFAULT 0,PRIMARY KEY (`day`,`client_id`)',
            'attempt' => '`provider_code` varchar(64) NOT NULL DEFAULT \'\',`id` bigint unsigned NOT NULL AUTO_INCREMENT,`delivery_id` bigint unsigned NOT NULL,`attempt` int NOT NULL,`status` varchar(16) NOT NULL,`reason` varchar(255) NOT NULL DEFAULT \'\',`http_code` int NOT NULL DEFAULT 0,`sms_units` int NOT NULL DEFAULT 0,`created_at` int NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `delivery_attempt` (`delivery_id`,`attempt`)',
            'delivery_item' => '`delivery_id` bigint unsigned NOT NULL,`item_id` bigint unsigned NOT NULL,`product_id` int NOT NULL,`event_type` varchar(16) NOT NULL,`channel` varchar(16) NOT NULL,`endpoint_id` bigint unsigned NOT NULL DEFAULT 0,`source` varchar(16) NOT NULL,`status` varchar(16) NOT NULL,`reason` varchar(255) NOT NULL DEFAULT \'\',`accepted_at` int NOT NULL DEFAULT 0,PRIMARY KEY (`delivery_id`,`item_id`),KEY `product_delivery` (`product_id`,`delivery_id`)',
            'review' => '`delivery_id` bigint unsigned NOT NULL,`outcome` varchar(16) NOT NULL,`note` varchar(500) NOT NULL,`admin_id` int NOT NULL,`updated_at` int NOT NULL,PRIMARY KEY (`delivery_id`)',
            'broadcast' => '`id` bigint unsigned NOT NULL AUTO_INCREMENT,`admin_id` int NOT NULL,`request_key` varchar(64) NOT NULL,`selection` text NOT NULL,`result` longtext NOT NULL,`created_at` int NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `request_key` (`request_key`)',
            'audit' => '`id` bigint unsigned NOT NULL AUTO_INCREMENT,`action` varchar(64) NOT NULL,`actor_id` int NOT NULL DEFAULT 0,`client_id` int NOT NULL DEFAULT 0,`detail` longtext NOT NULL,`created_at` int NOT NULL,PRIMARY KEY (`id`),KEY `created_at` (`created_at`)',
        ];
    }
    public static function install() : void
    {
        foreach (self::definitions() as $name => $definition) {
            $table = Db::connect()->getConfig('prefix') . Store::PREFIX . $name;
            if (!preg_match('/\A[a-zA-Z0-9_]+\z/', $table)) { throw new \RuntimeException('数据库表前缀不正确。'); }
            Db::execute('CREATE TABLE IF NOT EXISTS `' . $table . '` (' . $definition . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        }
        Store::table('setting')->extra('IGNORE')->insert(['id'=>1,'data'=>Store::json(Settings::defaults()),'updated_at'=>time()]);
        Keyring::install();
        // Additive migrations run only from install/after-upgrade, never in the
        // platform's upgrade transaction. Existing restock facts keep their type.
        $additions=[
            'item'=>['event_type'=>"varchar(16) NOT NULL DEFAULT 'restock'",'wait_code'=>"varchar(24) NOT NULL DEFAULT ''",'send_attempts'=>'int NOT NULL DEFAULT 0','attempted_at'=>'int NOT NULL DEFAULT 0'],
            'delivery'=>['archived_at'=>'int NOT NULL DEFAULT 0','event_type'=>"varchar(16) NOT NULL DEFAULT 'restock'"],
            'endpoint'=>['edit_revision'=>'int NOT NULL DEFAULT 1','admin_disabled'=>'tinyint NOT NULL DEFAULT 0'],
            'attempt'=>['provider_code'=>"varchar(64) NOT NULL DEFAULT ''"],
        ];
        $migrateBlocks=false;
        foreach ($additions as $name=>$fields) {
            $table=Db::connect()->getConfig('prefix') . Store::PREFIX . $name;
            $columns=array_column(Db::query('SHOW COLUMNS FROM `' . $table . '`'),'Field');
            foreach ($fields as $field=>$definition) {
                if (!in_array($field,$columns,true)) { if ($field==='admin_disabled') { $migrateBlocks=true; } Db::execute('ALTER TABLE `' . $table . '` ADD COLUMN `'.$field.'` '.$definition); }
            }
        }
        $itemTable=Db::connect()->getConfig('prefix') . Store::PREFIX . 'item';
        if (!Db::query('SHOW INDEX FROM `'.$itemTable.'` WHERE Key_name=\'merge_group\'')) {
            Db::execute('ALTER TABLE `'.$itemTable.'` ADD INDEX `merge_group` (`batch_key`,`status`,`client_id`,`channel`,`endpoint_id`,`receiver_hash`,`event_type`,`due_at`)');
        }
        $deliveryTable=Db::connect()->getConfig('prefix').Store::PREFIX.'delivery';
        if(!Db::query('SHOW INDEX FROM `'.$deliveryTable.'` WHERE Key_name=\'archive_time\'')) { Db::execute('ALTER TABLE `'.$deliveryTable.'` ADD INDEX `archive_time` (`archived_at`,`created_at`)'); }
        $prefix=Db::connect()->getConfig('prefix').Store::PREFIX;
        Db::execute('INSERT IGNORE INTO `'.$prefix.'delivery_item` (`delivery_id`,`item_id`,`product_id`,`event_type`,`channel`,`endpoint_id`,`source`,`status`,`reason`,`accepted_at`) SELECT `delivery_id`,`id`,`product_id`,`event_type`,`channel`,`endpoint_id`,`source`,`status`,`reason`,`accepted_at` FROM `'.$prefix.'item` WHERE `delivery_id`>0');
        Db::execute('UPDATE `'.$prefix.'item` i JOIN `'.$prefix.'delivery` d ON d.id=i.delivery_id SET i.send_attempts=GREATEST(i.send_attempts,d.attempts),i.attempted_at=IF(i.attempted_at=0 AND d.attempts>0,d.updated_at,i.attempted_at)');
        // A legacy administrator block must not become a customer-controlled switch.
        foreach ($migrateBlocks ? Store::table('audit')->where('action','personal_endpoint_disabled')->column('detail') : [] as $detail) {
            $id=(int)(Store::decode($detail)['endpoint_id'] ?? 0);
            if ($id) { Store::table('endpoint')->where('id',$id)->where('scope','personal')->where('enabled',0)->update(['admin_disabled'=>1]); }
        }
        Availability::seed();
        Notice::seed();
    }
    public static function syncHooks() : void
    {
        $allowed = ['after_plugin_install','after_plugin_upgrade','minute_cron','task_run','after_product_edit','after_product_create','after_product_delete','after_upstream_product_update','after_order_create','after_order_cancel','after_host_delete','after_host_terminate_success','cart_view_append','clientarea_view_append','append_send_param','append_send_param_by_action'];
        $status = (int)Db::name('plugin')->where('name','CrStockSubscribe')->where('module','addon')->value('status');
        Db::transaction(function () use ($allowed,$status) {
            Db::name('plugin_hook')->where('plugin','CrStockSubscribe')->where('module','addon')->delete();
            foreach ($allowed as $name) { Db::name('plugin_hook')->insert(['name'=>$name,'plugin'=>'CrStockSubscribe','module'=>'addon','status'=>$status]); }
            $navIds=Db::name('nav')->where('plugin','CrStockSubscribe')->where('type','admin')->where('name','nav_plugin_addon_cr_stock_subscribe')->column('id');
            // Repair only the untranslated default inserted by older installs.
            // Merchant-renamed navigation must remain untouched.
            if ($navIds) { Db::name('menu')->whereIn('nav_id',$navIds)->where('name','nav_plugin_addon_cr_stock_subscribe')->update(['name'=>lang_plugins('nav_plugin_stock_subscribe')]); }
        });
        \app\common\logic\CacheLogic::clearPluginCache();
    }
}
