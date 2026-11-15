<?php
declare(strict_types=1);
namespace ShopInit;

use RuntimeException;

require_once __DIR__ . '/content.php';

function allowedPaymentModules(): array
{
    return ['ps_wirepayment','ps_cashondelivery'];
}

function enabledPaymentModules(\Context $context): array
{
    $rows=\Db::getInstance()->executeS('SELECT DISTINCT m.name FROM '._DB_PREFIX_.'module m INNER JOIN '
        ._DB_PREFIX_.'module_shop ms ON ms.id_module=m.id_module INNER JOIN '._DB_PREFIX_
        . 'hook_module hm ON hm.id_module=m.id_module AND hm.id_shop=ms.id_shop INNER JOIN '
        ._DB_PREFIX_."hook h ON h.id_hook=hm.id_hook WHERE ms.id_shop=".(int)$context->shop->id
        ." AND h.name IN ('paymentOptions','payment','displayPayment') ORDER BY m.name");
    if($rows===false) throw new RuntimeException('Cannot list active payment modules.');
    return array_column($rows,'name');
}

function paymentInputs(\Context $context): array
{
    $shopId=(int)$context->shop->id;
    $countryId=(int)\Country::getByIso('PL');
    if(!$countryId || !(new \Country($countryId))->active) throw new RuntimeException('Active Poland is required for payments.');
    $query=new \DbQuery();
    $query->select('c.id_currency AS id')->from('currency','c');
    $query->innerJoin('currency_shop','s','s.id_currency=c.id_currency');
    $query->where("c.iso_code='PLN'")->where('c.active=1')->where('c.deleted=0')->where('s.id_shop='.$shopId);
    $currencyId=uniqueId($query);
    if($currencyId===null) throw new RuntimeException('Active PLN currency is required for payments.');
    $groups=[];
    foreach(['PS_UNIDENTIFIED_GROUP','PS_GUEST_GROUP','PS_CUSTOMER_GROUP'] as $setting) {
        $id=(int)\Configuration::get($setting);
        if(!$id || !\Validate::isLoadedObject(new \Group($id))) throw new RuntimeException('Missing customer payment group: '.$setting);
        $groups[]=$id;
    }
    $carriers=\Db::getInstance()->executeS('SELECT DISTINCT c.id_reference FROM '._DB_PREFIX_.'carrier c INNER JOIN '
        ._DB_PREFIX_.'carrier_shop s ON s.id_carrier=c.id_carrier WHERE c.deleted=0 AND s.id_shop='.$shopId.' ORDER BY c.id_reference');
    if($carriers===false || $carriers===[]) throw new RuntimeException('At least one existing carrier is required for payment permissions.');
    foreach(allowedPaymentModules() as $name) {
        if(!is_readable(_PS_MODULE_DIR_.$name.'/'.$name.'.php')) throw new RuntimeException('Required offline payment module files are missing: '.$name);
    }
    return ['country'=>$countryId,'currency'=>$currencyId,'groups'=>array_values(array_unique($groups)),
        'carriers'=>array_map(fn(array $row):int=>(int)$row['id_reference'],$carriers)];
}

function configurePayments(\Context $context,bool $dryRun=false): void
{
    $inputs=paymentInputs($context);
    $extra=array_values(array_diff(enabledPaymentModules($context),allowedPaymentModules()));
    if($dryRun) {
        echo 'PLAN ENABLE: '.implode(', ',allowedPaymentModules()).PHP_EOL;
        echo 'PLAN DISABLE: '.($extra===[]?'none':implode(', ',$extra)).PHP_EOL;
        echo "PLAN: Poland, PLN, visitor/guest/customer groups, current carrier references and test-only bank instructions.\nOK: dry run; no changes.\n";
        return;
    }
    echo "[1/3] Enabling offline bank transfer and cash on delivery...\n";
    $modules=[];
    foreach(allowedPaymentModules() as $name) {
        $module=\Module::getInstanceByName($name);
        if(!$module instanceof \PaymentModule) throw new RuntimeException('Unexpected payment module implementation: '.$name);
        if(!\Module::isInstalled($name) && !$module->install()) throw new RuntimeException('Cannot install offline payment module: '.$name);
        if(!$module->enable() || !$module->registerHook('paymentOptions')) throw new RuntimeException('Cannot enable offline payment module: '.$name);
        \Cache::clean('Module::isEnabled'.$name);
        $modules[$name]=$module;
    }
    foreach($extra as $name) {
        $module=\Module::getInstanceByName($name);
        if(!$module || !$module->disable()) throw new RuntimeException('Cannot disable unused payment module: '.$name);
        \Cache::clean('Module::isEnabled'.$name);
        echo 'DISABLED: '.$name.PHP_EOL;
    }
    echo "[2/3] Restoring payment permissions and demonstrational bank instructions...\n";
    $shopId=(int)$context->shop->id;
    transaction(function() use($context,$modules,$inputs,$shopId):void {
        $db=\Db::getInstance();
        foreach($modules as $module) {
            $permissionSets=[
                'module_country'=>['column'=>'id_country','values'=>[$inputs['country']]],
                'module_currency'=>['column'=>'id_currency','values'=>[$inputs['currency']]],
                'module_group'=>['column'=>'id_group','values'=>$inputs['groups']],
                'module_carrier'=>['column'=>'id_reference','values'=>$inputs['carriers']],
            ];
            foreach($permissionSets as $table=>$set) {
                if(!$db->delete($table,'id_module='.(int)$module->id.' AND id_shop='.$shopId)) throw new RuntimeException('Cannot reset payment permissions: '.$table);
                foreach($set['values'] as $value) {
                    if(!$db->insert($table,['id_module'=>(int)$module->id,'id_shop'=>$shopId,$set['column']=>(int)$value])) {
                        throw new RuntimeException('Cannot save payment permission: '.$table);
                    }
                }
            }
        }
        $settings=[
            'BANK_WIRE_OWNER'=>'Twoje Drzwi do Sukcesu — sklep demonstracyjny',
            'BANK_WIRE_DETAILS'=>'Płatność testowa — nie wykonuj rzeczywistego przelewu.',
            'BANK_WIRE_ADDRESS'=>'Środowisko demonstracyjne — brak rzeczywistego rachunku bankowego.',
            'BANK_WIRE_PAYMENT_INVITE'=>1,
        ];
        foreach($settings as $key=>$value) contentSetting($key,$value,$context);
        $texts=[];
        foreach(\Language::getLanguages(false) as $language) $texts[(int)$language['id_lang']]='Zamówienie testowe. Nie wykonuj rzeczywistego przelewu; status płatności zmienia administrator podczas weryfikacji projektu.';
        contentSetting('BANK_WIRE_CUSTOM_TEXT',$texts,$context);
    });
    echo "[3/3] Checking enabled payment methods...\n";
    $actual=enabledPaymentModules($context);
    $expected=allowedPaymentModules();
    sort($actual); sort($expected);
    if($actual!==$expected) throw new RuntimeException('Enabled checkout payment methods differ from the offline allowlist.');
    \Tools::clearSmartyCache();
    echo "OK: only bank transfer and cash on delivery enabled for PL/PLN; test bank data configured.\n";
}

function refreshPaymentInformationPage(\Context $context): void
{
    $definitions=informationalPageDefinitions();
    $ids=[];
    foreach($definitions as $key=>$definition) {
        $ids[$key]=(int)\Configuration::get('SHOP_INIT_CMS_'.strtoupper($key));
        if(!$ids[$key] || !\Validate::isLoadedObject(new \CMS($ids[$key]))) {
            echo "INFO: payment CMS content will be prepared by configure-content.php.\n";
            return;
        }
    }
    $languageId=(int)\Configuration::get('PS_LANG_DEFAULT');
    $escape=fn(string $url):string=>htmlspecialchars($url,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $tokens=informationalMethods($context);
    foreach($ids as $key=>$id) $tokens['{{'.$key.'_url}}']=$escape($context->link->getCMSLink($id,null,true,$languageId,(int)$context->shop->id));
    $tokens['{{contact_url}}']=$escape($context->link->getPageLink('contact',true,$languageId));
    $tokens['{{account_url}}']=$escape($context->link->getPageLink('my-account',true,$languageId));
    $templates=informationalTemplates();
    $cms=new \CMS($ids['payments'],null,(int)$context->shop->id);
    $cms->id_shop_list=[(int)$context->shop->id];
    $cms->content[$languageId]=renderInformationalTemplate($templates['payments'],$tokens);
    if(!$cms->update()) throw new RuntimeException('Cannot refresh payment information page.');
    \Tools::clearSmartyCache();
    echo "OK: payment information page refreshed.\n";
}
