<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/mail-templates.php';

try {
    $context=ShopInit\boot();
    if ((int)Configuration::get('PS_MAIL_METHOD')!==2 || Configuration::get('PS_MAIL_SERVER')!=='mailpit'
        || (int)Configuration::get('PS_MAIL_SMTP_PORT')!==1025) {
        throw new RuntimeException('This test may only send to the local Mailpit SMTP service.');
    }
    $languageId=(int)Configuration::get('PS_LANG_DEFAULT');
    $context->language=new Language($languageId);
    $locale=$context->language->locale;
    if($context->language->iso_code!=='pl') throw new RuntimeException('Polish language is required.');
    $identifier=bin2hex(random_bytes(6));
    $recipient='mail-pl-'.$identifier.'@example.test';
    $orderName='TEST-PL-'.strtoupper($identifier);
    $bankState=new OrderState((int)Configuration::get('PS_OS_BANKWIRE'),$languageId);
    $subjects=[
        'account'=>$context->getTranslator()->trans('Welcome!',[],'Emails.Subject',$locale),
        'bankwire'=>$bankState->name,
        'order_conf'=>$context->getTranslator()->trans('Order confirmation',[],'Emails.Subject',$locale),
    ];
    $vars=[
        '{firstname}'=>'Próba','{lastname}'=>'Tłumaczeń','{email}'=>$recipient,
        '{order_name}'=>$orderName,'{date}'=>'2026-11-13','{payment}'=>'Przelew bankowy',
        '{total_paid}'=>'59,99 zł','{total_products}'=>'59,99 zł','{total_discounts}'=>'0,00 zł',
        '{total_shipping}'=>'0,00 zł','{total_tax_paid}'=>'11,22 zł','{carrier}'=>'Dostawa kurierska',
        '{recycled_packaging_label}'=>'Nie',
        '{delivery_block_html}'=>'Próba Tłumaczeń<br>Adres testowy',
        '{delivery_block_txt}'=>"Próba Tłumaczeń\nAdres testowy",
        '{invoice_block_html}'=>'Próba Tłumaczeń<br>Adres testowy',
        '{invoice_block_txt}'=>"Próba Tłumaczeń\nAdres testowy",
        '{bankwire_owner}'=>'Twoje Drzwi do Sukcesu — test',
        '{bankwire_details}'=>'Dane testowe — nie wykonuj przelewu',
        '{bankwire_address}'=>'Bank testowy',
        '{products}'=>'<tr><td>TEST-PL</td><td>Zamek — kolor złoty</td><td>59,99 zł</td><td>1</td><td>59,99 zł</td></tr>',
        '{products_txt}'=>"TEST-PL\tZamek — kolor złoty\t59,99 zł\t1\t59,99 zł",
        '{discounts}'=>'','{discounts_txt}'=>'',
    ];
    foreach(ShopInit\polishTransactionalMailTemplates() as $template=>$expected) {
        foreach(['html','txt'] as $extension) {
            $body=file_get_contents(_PS_MAIL_DIR_.'pl/'.$template.'.'.$extension);
            if(!str_contains(strip_tags($body),$expected) || !mb_check_encoding($body,'UTF-8')) throw new RuntimeException('Polish template is missing: '.$template);
        }
        if(!Mail::Send($languageId,$template,$subjects[$template],$vars,$recipient,'Próba Tłumaczeń',null,null,null,null,_PS_MAIL_DIR_,false,(int)$context->shop->id)) {
            throw new RuntimeException('Failed to send test email: '.$template);
        }
    }
    $api=function(string $path):array {
        $curl=curl_init('http://mailpit:8025'.$path);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);
        $body=curl_exec($curl);
        if($body===false || curl_getinfo($curl,CURLINFO_HTTP_CODE)!==200) throw new RuntimeException('Local Mailpit API request failed.');
        return json_decode($body,true,512,JSON_THROW_ON_ERROR);
    };
    $messages=[];
    for($attempt=0;$attempt<20;$attempt++) {
        $search=$api('/api/v1/search?query='.rawurlencode('to:'.$recipient));
        $messages=$search['messages']??[];
        if(count($messages)===3) break;
        usleep(200000);
    }
    if(count($messages)!==3) throw new RuntimeException('Mailpit did not capture exactly three test messages.');
    $matched=[];
    foreach($messages as $summary) {
        $mail=$api('/api/v1/message/'.$summary['ID']);
        $template=null;
        foreach($subjects as $key=>$subject) if(str_contains($mail['Subject']??'',$subject)) $template=$key;
        if($template===null || isset($matched[$template])) throw new RuntimeException('Unexpected/duplicate test email subject.');
        foreach(['HTML','Text'] as $format) {
            $body=$mail[$format]??'';
            $visible=html_entity_decode(strip_tags($body),ENT_QUOTES|ENT_HTML5,'UTF-8');
            if(!str_contains($visible,'Próba Tłumaczeń') || !str_contains($visible,ShopInit\polishTransactionalMailTemplates()[$template])) {
                throw new RuntimeException('Received email is not Polish: '.$template.' '.$format);
            }
            foreach(['Hi ','Thank you for','Order details','Pending payment','Account owner:','Payment method:','Powered by'] as $english) {
                if(str_contains($visible,$english)) throw new RuntimeException('English content remains in '.$template.' '.$format.': '.$english);
            }
            if(ShopInit\mailTemplatePlaceholders($body)!==[]) throw new RuntimeException('Unresolved placeholders in received '.$template.' '.$format);
            if($template!=='account' && (!str_contains($visible,$orderName) || !str_contains($visible,'59,99 zł'))) throw new RuntimeException('Order reference/total missing from received email.');
            if($template==='order_conf' && (!str_contains($visible,'Zamek — kolor złoty') || !str_contains($visible,'Dostawa kurierska'))) throw new RuntimeException('Order product/delivery data was lost.');
        }
        $matched[$template]=true;
        echo 'MAIL OK: '.$template.' — '.$mail['Subject'].'; Polish HTML/text and resolved data.'.PHP_EOL;
    }
    echo 'OK: three controlled emails captured in local Mailpit for '.$recipient.'. No customer or order created.'.PHP_EOL;
} catch(Throwable $error) {
    fwrite(STDERR,'TEST FAILED: '.$error->getMessage().PHP_EOL);
    exit(1);
}
