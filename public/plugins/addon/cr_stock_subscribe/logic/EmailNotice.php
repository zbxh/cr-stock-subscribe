<?php
namespace addon\cr_stock_subscribe\logic;

/** Keep V10's notice lookup, hooks, transport and logs; render each mail context. */
class EmailNotice extends \app\common\logic\EmailLogic
{
    public function sendBase($param)
    {
        $plain=[]; $html=[];
        foreach ($param['template_param'] as $key=>$value) {
            $placeholder='{'.$key.'}';
            $plain[$placeholder]=$value;
            // The native mail adapter decodes one HTML entity layer. Plain
            // product text needs two here, while generated HTML stays intact.
            $html[$placeholder]=in_array($key,['stock_product_name','stock_product_short_name','stock_product_list'],true)
                ? htmlspecialchars(htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'),ENT_QUOTES,'UTF-8')
                : $value;
        }
        $param['subject']=strtr($param['subject'],$plain);
        $param['message']=strtr($param['message'],$html);
        // Already rendered once: a product name containing a template token is
        // literal text, not another instruction to the native replacement loop.
        $param['template_param']=[];
        return parent::sendBase($param);
    }
}
