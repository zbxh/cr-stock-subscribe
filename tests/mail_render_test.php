<?php
// Standalone rendering contract: no site, database or real mail transport.
namespace app\common\logic {
    class EmailLogic
    {
        public function sendBase($param) { return $param; }
    }
}
namespace {
    require dirname(__DIR__).'/public/plugins/addon/cr_stock_subscribe/logic/EmailNotice.php';
    $count=0;
    function check($name,$ok) { global $count; if (!$ok) { throw new \RuntimeException($name); } $count++; echo 'PASS '.$name."\n"; }
    $name='A&B "香港" <script>alert(1)</script>';
    $vars=['stock_product_name'=>$name,'stock_product_short_name'=>'A&B "港" <script>',
        'stock_product_list'=>"$name\n第二款",'stock_product_count'=>2,
        'stock_product_list_html'=>'<table><tr><td>A&amp;amp;B</td></tr></table>',
        'stock_manage_url'=>'https://example.invalid/plugin/1/stock_subscribe.htm'];
    $source=['email'=>'customer@example.invalid','email_name'=>'Demo','attachments'=>'',
        'subject'=>'{stock_product_name} / {stock_product_count} 款已补货',
        'message'=>'<h1>{stock_product_name}</h1><p>{stock_product_short_name}</p><pre>{stock_product_list}</pre>{stock_product_list_html}<a href="{stock_manage_url}">管理订阅</a>',
        'template_param'=>$vars];
    $rendered=(new \addon\cr_stock_subscribe\logic\EmailNotice())->sendBase($source);
    // V10 returns this message in its log and decodes one layer for transport.
    $wire=htmlspecialchars_decode($rendered['message']);
    check('subject preserves literal ampersands quotes and angle brackets',$rendered['subject']===$name.' / 2 款已补货');
    check('mail body treats the full name as text',strpos($wire,'<h1>'.htmlspecialchars($name,ENT_QUOTES,'UTF-8').'</h1>')!==false);
    check('short product name is also safe body text',strpos($wire,'<p>A&amp;B &quot;港&quot; &lt;script&gt;</p>')!==false);
    check('plain product list remains escaped text with line breaks',strpos($wire,"<pre>".htmlspecialchars($vars['stock_product_list'],ENT_QUOTES,'UTF-8').'</pre>')!==false);
    check('generated product table keeps native HTML',strpos($wire,'<table><tr><td>A&amp;B</td></tr></table>')!==false);
    check('merchant link parameter remains usable',strpos($wire,'href="'.$vars['stock_manage_url'].'"')!==false);
    check('native send parameters are preserved',array_intersect_key($rendered,array_flip(['email','email_name','attachments']))===array_intersect_key($source,array_flip(['email','email_name','attachments'])));
    check('native replacement cannot render parameters a second time',$rendered['template_param']===[]);
    check('source template and variables are not changed',$source['subject']==='{stock_product_name} / {stock_product_count} 款已补货' && $source['template_param']===$vars);
    $literal=$source; $literal['template_param']['stock_product_name']='商品 {stock_manage_url}';
    $again=(new \addon\cr_stock_subscribe\logic\EmailNotice())->sendBase($literal);
    check('template-looking product names remain literal',strpos($again['subject'],'商品 {stock_manage_url}')===0 && strpos($again['message'],'<h1>商品 {stock_manage_url}</h1>')===0);
    echo 'PASS '.$count." standalone mail rendering checks\n";
}
