<?php
namespace addon\cr_stock_subscribe\logic;

/** DNS protocol handling belongs to Net_DNS2; this adapter selects host addresses. */
class Resolver
{
    public function lookup(string $host, float $deadline) : array
    {
        $host=strtolower(rtrim($host,'.'));
        $local=[];
        foreach (@file('/etc/hosts',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $parts=preg_split('/\s+/',trim(explode('#',$line,2)[0]));
            if (count($parts)>1 && in_array($host,array_map('strtolower',array_slice($parts,1)),true) && filter_var($parts[0],FILTER_VALIDATE_IP)) { $local[]=$parts[0]; }
        }
        if ($local) { return array_values(array_unique($local)); }
        $ips=[];
        foreach (['A','AAAA'] as $type) {
            $name=$host; $seen=[];
            for ($hop=0;$hop<8 && microtime(true)<$deadline;$hop++) {
                if (isset($seen[$name])) { throw new \DomainException('Webhook 域名别名循环。'); }
                $seen[$name]=true;
                try { $answer=$this->query($name,$type,$deadline)->answer; }
                catch (\Net_DNS2_Exception $e) { break; }
                $addresses=[]; $aliases=[];
                foreach ($answer as $record) {
                    $owner=strtolower(rtrim($record->name,'.'));
                    if ($record->type===$type && filter_var($record->address,FILTER_VALIDATE_IP)) { $addresses[$owner][]=$record->address; }
                    elseif ($record->type==='CNAME') { $aliases[$owner]=strtolower(rtrim($record->cname,'.')); }
                }
                // Only the queried owner and its CNAME chain may contribute
                // addresses. Unrelated answer/additional records are not targets.
                $walk=$name; $chain=[]; $found=[];
                for ($i=0;$i<8;$i++) {
                    if (isset($chain[$walk])) { throw new \DomainException('Webhook 域名别名循环。'); }
                    $chain[$walk]=true;
                    $found=array_merge($found,$addresses[$walk] ?? []);
                    if (!isset($aliases[$walk])) { break; }
                    $walk=$aliases[$walk];
                }
                if ($found) { $ips=array_merge($ips,$found); break; }
                if ($walk===$name) { break; }
                $name=$walk;
            }
        }
        // One address family may be unavailable. Every returned IP is checked
        // for public routing and pinned by Endpoint/Webhook before any HTTP IO.
        if (!$ips) { throw new \DomainException('Webhook 域名暂时无法解析或解析超时，请检查服务器 DNS 后重试。'); }
        return array_values(array_unique($ips));
    }
    protected function query(string $name, string $type, float $deadline)
    {
        require_once dirname(__DIR__).'/vendor/net_dns2/autoload.php';
        $resolver=new \Net_DNS2_Resolver(['nameservers'=>\Net_DNS2::RESOLV_CONF]);
        $resolver->nameservers=array_slice($resolver->nameservers,0,3);
        // Budget for server failover, UDP/TCP fallback and socket operations.
        $resolver->timeout=min(1.0,max(0.001,($deadline-microtime(true))/(4*max(1,count($resolver->nameservers)))));
        return $resolver->query($name,$type);
    }
}
