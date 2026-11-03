<?php
namespace addon\cr_stock_subscribe\logic;

final class Filters
{
    public static function text(array $input, string $key, int $max = 100) : string
    {
        $value=$input[$key] ?? '';
        if (!is_scalar($value)) { throw new \DomainException('筛选条件格式不正确。'); }
        $value=trim((string)$value);
        if (mb_strlen($value)>$max) { throw new \DomainException('搜索内容过长，请缩短后重试。'); }
        return $value;
    }
    public static function choice(array $input, string $key, array $values) : string
    {
        $value=self::text($input,$key,64);
        if ($value!=='' && !in_array($value,$values,true)) { throw new \DomainException('筛选选项不正确。'); }
        return $value;
    }
    public static function id(array $input, string $key) : int
    {
        $value=self::text($input,$key,16);
        return $value==='' ? 0 : Store::number($value,1,2147483647,'筛选编号');
    }
    public static function like(string $value) : string { return '%'.strtr($value,['\\'=>'\\\\','%'=>'\\%','_'=>'\\_']).'%'; }
    public static function dates($query, array $input, string $field) : void
    {
        $bounds=[];
        foreach (['date_from','date_to'] as $key) {
            $value=self::text($input,$key,10); $bounds[$key]=null;
            if ($value==='') { continue; }
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
            if (!$date || $date->format('Y-m-d')!==$value) { throw new \DomainException('日期格式不正确。'); }
            $bounds[$key]=$date;
        }
        if ($bounds['date_from'] && $bounds['date_to'] && $bounds['date_from']>$bounds['date_to']) { throw new \DomainException('开始日期不能晚于结束日期。'); }
        if ($bounds['date_from']) { $query->where($field,'>=',$bounds['date_from']->getTimestamp()); }
        if ($bounds['date_to']) { $query->where($field,'<',$bounds['date_to']->modify('+1 day')->getTimestamp()); }
    }
    public static function subscriptionStatus($query, string $status) : void
    {
        if ($status==='active') { $query->where('s.status','active')->where('s.expires_at','>',time()); }
        elseif ($status==='expired') { $query->where(function ($q) { $q->where('s.status','expired')->whereOr(function ($q) { $q->where('s.status','active')->where('s.expires_at','<=',time()); }); }); }
        elseif ($status!=='') { $query->where('s.status',$status); }
    }
}
