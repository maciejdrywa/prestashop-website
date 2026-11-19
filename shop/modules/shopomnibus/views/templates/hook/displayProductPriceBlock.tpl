{**
 * Szablon prezentacji najniższej ceny Omnibus (PAN DOMU).
 *}
{if !empty($omnibus_lowest_price)}
  <div class="shopomnibus-price-notice{if !empty($omnibus_is_listing)} shopomnibus-listing{/if}" data-raw-price="{$omnibus_raw_price}" data-under-30-days="{if $omnibus_is_under_30_days}1{else}0{/if}">
    <span class="shopomnibus-label">{$omnibus_label}</span>
    <span class="shopomnibus-price">{$omnibus_lowest_price}</span>
  </div>
{/if}
