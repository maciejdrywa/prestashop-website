/**
 * Pozycja koszyka lub zamówienia rozpoznawana po ID, a nie po nazwie.
 *
 * Nazwa wariantu w zamówieniu jest tylko tekstem dopisanym do nazwy produktu,
 * więc dwa warianty tego samego produktu (np. biały i grafitowy zatrzask)
 * przy tej samej ilości byłyby nie do odróżnienia. Para ID produktu i ID wariantu
 * (id_product_attribute, 0 dla produktu bez wariantów) jednoznacznie wskazuje pozycję.
 */
export type Pozycja = {
  idProduktu: string;
  idWariantu: string;
  ilosc: number;
  nazwa?: string;
};


/** Normalizuje ID z atrybutu HTML ("12", " 12 ") do postaci porównywalnej; zwraca '' dla braku ID. */
export function normalizujId(wartosc: string | null | undefined): string {
  const tekst = (wartosc ?? '').trim();

  return /^\d+$/.test(tekst) ? tekst.replace(/^0+(?=\d)/, '') : '';
}


function klucz(p: Pozycja): string {
  return `${p.idProduktu}:${p.idWariantu}`;
}


function opis(p: Pozycja): string {
  return `${p.nazwa ?? 'produkt'} (produkt ${p.idProduktu}, wariant ${p.idWariantu})`;
}


/**
 * Porównuje pozycje zamówienia z pozycjami koszyka po parze (ID produktu, ID wariantu).
 * Zwraca listę rozbieżności; pusta lista oznacza zgodność produktów, wariantów i ilości.
 */
export function roznicePozycji(oczekiwane: Pozycja[], zamowione: Pozycja[]): string[] {
  const bledy: string[] = [];

  for (const p of [...oczekiwane, ...zamowione]) {
    if (!p.idProduktu || p.idProduktu === '0' || !p.idWariantu) bledy.push(`Pozycja bez ID produktu lub wariantu: ${opis(p)}`);
    if (!Number.isInteger(p.ilosc) || p.ilosc <= 0) bledy.push(`Nieprawidłowa ilość ${p.ilosc}: ${opis(p)}`);
  }

  // Ta sama para (produkt, wariant) dwa razy w koszyku oznacza błąd danych testu, a nie zgodność.
  const policzone = new Map<string, number>();
  for (const o of oczekiwane) policzone.set(klucz(o), (policzone.get(klucz(o)) ?? 0) + 1);
  for (const [k, n] of policzone) {
    if (n > 1) bledy.push(`Pozycja występuje w koszyku ${n} razy: produkt:wariant ${k}`);
  }

  for (const o of oczekiwane.filter((o, i) => oczekiwane.findIndex(x => klucz(x) === klucz(o)) === i)) {
    const trafione = zamowione.filter(z => klucz(z) === klucz(o));

    if (trafione.length === 0) {
      bledy.push(`Brak w zamówieniu: ${opis(o)}`);
    } else if (trafione.length > 1) {
      bledy.push(`Pozycja występuje w zamówieniu ${trafione.length} razy: ${opis(o)}`);
    } else if (trafione[0].ilosc !== o.ilosc) {
      bledy.push(`Zła ilość w zamówieniu: ${opis(o)}, oczekiwano ${o.ilosc}, jest ${trafione[0].ilosc}`);
    }
  }

  const klucze = new Set(oczekiwane.map(klucz));
  for (const z of zamowione) {
    if (!klucze.has(klucz(z))) bledy.push(`Nieoczekiwana pozycja w zamówieniu: ${opis(z)}`);
  }

  return bledy;
}
