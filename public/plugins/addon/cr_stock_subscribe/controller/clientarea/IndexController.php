<?php
namespace addon\cr_stock_subscribe\controller\clientarea;

use app\event\controller\PluginBaseController;
use addon\cr_stock_subscribe\logic\Store;
use addon\cr_stock_subscribe\logic\Service;
use addon\cr_stock_subscribe\logic\Subscriptions;
use addon\cr_stock_subscribe\logic\Endpoint;

class IndexController extends PluginBaseController
{
    private function client() : int { $id=(int)get_client_id(false); if (!$id) { throw new \DomainException('请先登录。'); } return $id; }
    public function entry() { return Store::response(function () { return (new Service())->entry($this->client()); }); }
    public function products() { return Store::response(function () { Store::requireActive(); return (new Service())->products($this->request->param()); }); }
    public function subscriptions() { return Store::response(function () { return (new Subscriptions())->listing($this->client(),$this->request->param()); }); }
    public function save() { return Store::response(function () { return (new Subscriptions())->save($this->client(),$this->request->param(),true); }); }
    public function cancel() { return Store::response(function () { (new Subscriptions())->cancel($this->client(),(int)$this->request->param('id')); return []; }); }
    public function endpoints() { return Store::response(function () { return (new Endpoint())->listing($this->client()); }); }
    public function saveEndpoint() { return Store::response(function () { $input=$this->request->param(); $revision=empty($input['id']) ? null : Store::number($input['revision'] ?? null,1,2147483647,'地址版本（请重新打开编辑）'); return (new Endpoint())->save($this->client(),$input, 0,$revision); }); }
    public function deleteEndpoint() { return Store::response(function () { (new Endpoint())->delete((int)$this->request->param('id'),$this->client()); return []; }); }
    public function testEndpoint() { return Store::response(function () { return (new Service())->testEndpoint((int)$this->request->param('id'),$this->client()); }); }
    public function records() { return Store::response(function () { return (new Service())->records($this->client(),$this->request->param()); }); }
}
