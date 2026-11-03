<?php
namespace addon\cr_stock_subscribe\logic;

final class Diagnostics
{
    public static function reason(string $text) : string
    {
        $text=strip_tags($text);
        $text=preg_replace('/https?:\/\/[^\s<>]+/iu','[地址已隐藏]',$text);
        $text=preg_replace('/[\w.+-]+@[\w.-]+\.[a-z]{2,}/iu','[邮箱已隐藏]',$text);
        $text=preg_replace('/(?:secret|token|password|authorization|access_token|key)\s*[:=]\s*[^\s,;]+/iu','[密钥已隐藏]',$text);
        $text=preg_replace('/(?<![a-z0-9])[+]?\d[\d -]{6,}\d(?![a-z0-9])/iu','[号码已隐藏]',$text);
        return mb_substr(preg_replace('/[\x00-\x1f\x7f]/u',' ',(string)$text),0,220);
    }

    public static function code($code) : string
    {
        return preg_match('/\A[a-zA-Z0-9_.:-]{1,64}\z/',(string)$code) ? (string)$code : '';
    }

    public static function fault(string $scope, int $id, \Throwable $error) : array
    {
        $ref=substr(hash('sha256',get_class($error).':'.$error->getMessage()),0,12);
        error_log('cr_stock_subscribe '.$scope.' #'.$id.' ['.$ref.'] '.get_class($error).' '.self::reason($error->getMessage()));
        return ['scope'=>$scope,'id'=>$id,'reference'=>$ref];
    }
}
