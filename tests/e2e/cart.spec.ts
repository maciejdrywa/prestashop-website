import { test, expect, Page } from '@playwright/test';

test("cart test" async ({page}) => {
const katA =[{'Śruby do łączenia blatów kompaktowych HLP (kpl 3szt.','https://pandomu.com.pl/akcesoria-meblowe/570-sruby-do-laczenia-blatow-kompaktowych-hlp-kpl-3szt.html',5},{'Zatrzask meblowy plastikowy','https://pandomu.com.pl/akcesoria-meblowe/530-zatrzask-meblowy-plastikowy.html', 5},{'Śruby do łączenia blatów 150mm EASIBOLT kpl 3szt.','https://pandomu.com.pl/elementy-konstrukcyjne/571-sruby-do-laczenia-blatow-zlacze-do-blatow-kuchennych-kpl-3szt.html',2},{'Stopka Meblowa Regulowana M8 (10szt)','https://pandomu.com.pl/elementy-konstrukcyjne/584-stopka-meblowa-regulowana-m8-10szt.html',7},{'Kołki meblowe montażowe 8x30, 100szt','https://pandomu.com.pl/elementy-konstrukcyjne/598-kolki-meblowe-8x30.html',2}];
const katB =[{'Klamka BRAVO z szyldem kwadratowym','https://pandomu.com.pl/klamki-z-szyldem-kwartatowym/125-680-klamka-z-szyldem-kwadratowym-bravo.html#/24-wybierz_kolor-nikiel_chrom',7 },{'Klamka FOCUS z szyldem kwadratowym - wersje','https://pandomu.com.pl/klamki-z-szyldem-kwartatowym/126-683-klamka-focus-z-szyldem-kwadratowym.html#/22-wybierz_kolor-patyna',2},{'Klamka TORINO z szyldem kwadratowym','https://pandomu.com.pl/klamki-z-szyldem-kwartatowym/129-688-klamka-torino-z-szyldem-kwadratowym.html#/24-wybierz_kolor-nikiel_chrom',1},{'Klamka QUEEN z szyldem kwadratowym','https://pandomu.com.pl/klamki-z-szyldem-kwartatowym/131-692-klamka-queen-z-szyldem-kwadratowym.html#/23-wybierz_kolor-nikiel_satyna',8},{'Klamka AKCENT z szyldem kwadratowym','https://pandomu.com.pl/klamki-z-szyldem-kwartatowym/134-klamka-akcent-z-szyldem-kwadratowym.html',2}];
const produkty  = katA +katB;

for(const p of produkt)
{
    dodajProdukt(p.url, p);
}




})


type Produkt = { nazwa: string; url: string; ilosc: number };
async function dodajProdukt(page:Page, produkt:Produkt)
{
await page.goto(produkt.url);

const btn = page.getByRole('button'{name: 'add-to-cart'});
await poleIlosc.fill(String(produkt.ilosc));

const btn = page.locator('quantity_wanted');
await btn.click();

const potwierdzenie = page.locator('blockcart-modal')
await expect(potwierdzenie).toBeVisible();
}


async function checkCart(page:Page,url:string)
{
    await page.goto(url);

    const ile = get.locator('cart-products-count');
    await ile.toHaveCount(10);

}