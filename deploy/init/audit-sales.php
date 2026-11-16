<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/prestashop.php';

try {
    $context=ShopInit\boot();
    $db=Db::getInstance();
    $shopId=(int)$context->shop->id;
    $langId=(int)Configuration::get('PS_LANG_DEFAULT');
    $query=function(string $sql) use($db):array {
        $rows=$db->executeS($sql);
        if($rows===false) throw new RuntimeException('Cannot read sales configuration.');
        return $rows;
    };
    $settings=[];
    foreach(['PS_SHIPPING_HANDLING','PS_SHIPPING_FREE_PRICE','PS_SHIPPING_FREE_WEIGHT','PS_SHIPPING_METHOD','PS_CARRIER_DEFAULT',
        'PS_WEIGHT_UNIT','PS_TAX','PS_INVOICE','PS_INVOICE_START_NUMBER','PS_INVOICE_MODEL','PS_CONDITIONS','PS_CONDITIONS_CMS_ID',
        'PS_OS_BANKWIRE','PS_OS_PAYMENT','PS_OS_COD_VALIDATION'] as $key) $settings[$key]=Configuration::get($key);
    $settings['PS_INVOICE_PREFIX']=Configuration::get('PS_INVOICE_PREFIX',$langId);
    $paymentIds=array_map(fn($row)=>(int)$row['id_module'],$query('SELECT DISTINCT m.id_module FROM '._DB_PREFIX_
        . 'module m INNER JOIN '._DB_PREFIX_.'hook_module hm ON hm.id_module=m.id_module INNER JOIN '
        . _DB_PREFIX_."hook h ON h.id_hook=hm.id_hook WHERE hm.id_shop=".$shopId." AND h.name IN ('paymentOptions','payment','displayPayment') ORDER BY m.id_module"));
    $paymentModules=[];
    foreach($paymentIds as $id) {
        $name=(string)$db->getValue('SELECT name FROM '._DB_PREFIX_.'module WHERE id_module='.$id);
        $paymentModules[]=['id_module'=>$id,'name'=>$name,'enabled'=>Module::isEnabled($name),
            'countries'=>$query('SELECT c.iso_code FROM '._DB_PREFIX_.'module_country m INNER JOIN '._DB_PREFIX_.'country c ON c.id_country=m.id_country WHERE m.id_module='.$id.' AND m.id_shop='.$shopId),
            'currencies'=>$query('SELECT m.id_currency,c.iso_code FROM '._DB_PREFIX_.'module_currency m LEFT JOIN '._DB_PREFIX_.'currency c ON c.id_currency=m.id_currency WHERE m.id_module='.$id.' AND m.id_shop='.$shopId),
            'groups'=>$query('SELECT id_group FROM '._DB_PREFIX_.'module_group WHERE id_module='.$id.' AND id_shop='.$shopId),
            'carrier_references'=>$query('SELECT id_reference FROM '._DB_PREFIX_.'module_carrier WHERE id_module='.$id.' AND id_shop='.$shopId)];
    }
    $states=[];
    foreach(OrderState::getOrderStates($langId) as $state) $states[]=array_intersect_key($state,array_flip(['id_order_state','name','module_name','invoice','paid','logable','send_email','template','pdf_invoice','pdf_delivery','delivery']));
    $bank=[];
    foreach(['BANK_WIRE_OWNER','BANK_WIRE_DETAILS','BANK_WIRE_ADDRESS'] as $key) $bank[$key.'_configured']=trim((string)Configuration::get($key))!=='';
    $issuer=[];
    foreach(['PS_SHOP_NAME','PS_SHOP_ADDR1','PS_SHOP_CODE','PS_SHOP_CITY','PS_SHOP_DETAILS'] as $key) $issuer[$key.'_configured']=trim((string)Configuration::get($key))!=='';
    $report=['version'=>_PS_VERSION_,'shop_id'=>$shopId,'currency'=>(new Currency((int)Configuration::get('PS_CURRENCY_DEFAULT')))->iso_code,
        'country'=>(new Country((int)Configuration::get('PS_COUNTRY_DEFAULT')))->iso_code,
        'settings'=>$settings,'bank_settings'=>$bank,'invoice_issuer_fields'=>$issuer,
        'payments'=>$paymentModules,
        'carriers'=>$query('SELECT c.id_carrier,c.id_reference,c.name,c.active,c.deleted,c.is_free,c.shipping_method,c.range_behavior,c.max_weight,c.is_module,c.external_module_name,l.delay FROM '._DB_PREFIX_.'carrier c INNER JOIN '._DB_PREFIX_.'carrier_shop s ON s.id_carrier=c.id_carrier LEFT JOIN '._DB_PREFIX_.'carrier_lang l ON l.id_carrier=c.id_carrier AND l.id_shop=s.id_shop AND l.id_lang='.$langId.' WHERE s.id_shop='.$shopId.' AND c.deleted=0 ORDER BY c.id_carrier'),
        'price_ranges'=>$query('SELECT r.* FROM '._DB_PREFIX_.'range_price r INNER JOIN '._DB_PREFIX_.'carrier_shop s ON s.id_carrier=r.id_carrier WHERE s.id_shop='.$shopId),
        'weight_ranges'=>$query('SELECT r.* FROM '._DB_PREFIX_.'range_weight r INNER JOIN '._DB_PREFIX_.'carrier_shop s ON s.id_carrier=r.id_carrier WHERE s.id_shop='.$shopId),
        'delivery_fees'=>$query('SELECT d.* FROM '._DB_PREFIX_.'delivery d INNER JOIN '._DB_PREFIX_.'carrier_shop s ON s.id_carrier=d.id_carrier WHERE s.id_shop='.$shopId),
        'order_states'=>$states];
    echo json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
} catch(Throwable $error) {
    fwrite(STDERR,'AUDIT FAILED: '.$error->getMessage().PHP_EOL);
    exit(1);
}
