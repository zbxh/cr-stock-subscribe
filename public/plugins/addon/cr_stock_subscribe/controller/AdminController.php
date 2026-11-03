<?php
namespace addon\cr_stock_subscribe\controller;

use app\event\controller\PluginAdminBaseController;
use addon\cr_stock_subscribe\logic\Store;
use addon\cr_stock_subscribe\logic\Settings;
use addon\cr_stock_subscribe\logic\Service;
use addon\cr_stock_subscribe\logic\Subscriptions;
use addon\cr_stock_subscribe\logic\Inventory;
use addon\cr_stock_subscribe\logic\Endpoint;
use addon\cr_stock_subscribe\logic\Engine;
use addon\cr_stock_subscribe\logic\Worker;

class AdminController extends PluginAdminBaseController
{
    public function settings() { return Store::response(function () { return (new Service())->adminSettings(); }); }
    public function saveSettings() { return Store::response(function () { $input=$this->request->param(); return Settings::save($input,(int)get_admin_id(),\addon\cr_stock_subscribe\logic\Filters::text($input,'settings_revision',64)); }); }
    public function products() { return Store::response(function () { return (new Service())->products($this->request->param(),true); }); }
    public function saveProducts() { return Store::response(function () { (new Inventory())->configure(Store::ids($this->request->param('product_ids',[])),Store::number($this->request->param('enabled',1),0,1,'开放状态')===1,(int)get_admin_id(),\addon\cr_stock_subscribe\logic\Filters::text($this->request->param(),'preview_fingerprint',64)); return []; }); }
    public function subscriptions() { return Store::response(function () { return (new Subscriptions())->listing(0,$this->request->param(),true); }); }
    public function cancel() { return Store::response(function () { (new Subscriptions())->cancel(0,(int)$this->request->param('id'),(int)get_admin_id()); return []; }); }
    public function endpoints() { return Store::response(function () { return (new Endpoint())->listing(0,true); }); }
    public function saveEndpoint() { return Store::response(function () { $input=$this->request->param(); $revision=empty($input['id']) ? null : Store::number($input['revision'] ?? null,1,2147483647,'地址版本（请重新打开编辑）'); return (new Endpoint())->save(0,$input, (int)get_admin_id(),$revision); }); }
    public function deleteEndpoint() { return Store::response(function () { (new Endpoint())->delete((int)$this->request->param('id'),0,(int)get_admin_id()); return []; }); }
    public function testEndpoint() { return Store::response(function () { return (new Service())->testEndpoint((int)$this->request->param('id'),0,(int)get_admin_id()); }); }
    public function personalEndpoints() { return Store::response(function () { return (new Endpoint())->listing(Store::number($this->request->param('client_id'),1,2147483647,'客户编号')); }); }
    public function disablePersonalEndpoint() { return Store::response(function () { (new Endpoint())->disablePersonal((int)$this->request->param('id'),(int)get_admin_id()); return []; }); }
    public function enablePersonalEndpoint() { return Store::response(function () { (new Endpoint())->enablePersonal((int)$this->request->param('id'),(int)get_admin_id()); return []; }); }
    public function review() { return Store::response(function () { (new Service())->review((int)$this->request->param('id'),$this->request->param(),(int)get_admin_id()); return []; }); }
    public function preview() { return Store::response(function () { return (new Engine())->broadcast($this->request->param(),(int)get_admin_id()); }); }
    public function broadcast() { return Store::response(function () { return (new Engine())->broadcast($this->request->param(),(int)get_admin_id(),true); }); }
    public function records() { return Store::response(function () { return (new Service())->records(0,$this->request->param(),true); }); }
    public function retry() { return Store::response(function () { (new Worker())->retry((int)$this->request->param('id'),(int)get_admin_id()); return []; }); }
}
