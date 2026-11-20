{if $hookName === 'displayHome'}
  {assign var='tile_images' value=['Klamki do drzwi' => 'klamki.jpg','Okucia drzwiowe' => 'okucia-drzwiowe.jpg','Bramy i ogrodzenia' => 'bramy.jpg','Okucia budowlane' => 'okucia-budowlane.jpg']}
  {foreach $linkBlocks as $block}
    {if $block.title === 'Kategorie'}
      {* Używamy adresu wygenerowanego dla kafla, ponieważ ID kategorii zależy od instalacji. *}
      {foreach $block.links as $link}
        {if $link.title === 'Klamki do drzwi'}
          <section class="pd-home-banner" aria-labelledby="pd-home-banner-title">
            <div class="pd-home-banner-intro">
              <img src="{$urls.child_img_url}baner-klamki.webp" width="160" height="160" alt="Klamka w otwartej paczce">
              <div>
                <h2 id="pd-home-banner-title">Klamki i okucia do drzwi</h2>
                <p>Rozwiązania do domu i ogrodu</p>
              </div>
            </div>
            <div class="pd-home-banner-help">
              <i class="material-icons" aria-hidden="true">headset_mic</i>
              <div>
                <strong>Potrzebujesz pomocy?</strong>
                <p>Doradzimy w wyborze okuć</p>
              </div>
            </div>
            <a class="pd-home-banner-link" href="{$link.url|escape:'html':'UTF-8'}">
              Zobacz klamki <i class="material-icons" aria-hidden="true">arrow_forward</i>
            </a>
          </section>
          {break}
        {/if}
      {/foreach}
    {/if}
    <section class="pd-category-tiles" aria-label="{$block.title|escape:'html':'UTF-8'}">
      {foreach $block.links as $link}
        <a href="{$link.url|escape:'html':'UTF-8'}">
          {if isset($tile_images[$link.title])}
            <img src="{$urls.child_img_url}{$tile_images[$link.title]}" width="450" height="280"
                 alt="{$link.title|escape:'html':'UTF-8'} — zobacz ofertę">
          {else}
            {$link.title|escape:'html':'UTF-8'}
          {/if}
        </a>
      {/foreach}
    </section>
  {/foreach}
{elseif $hookName === 'displayFooterBefore'}
  {foreach $linkBlocks as $block}
    <section class="pd-about">
      <div class="container">
        <h2>F.H. PAN DOMU – Klamki i okucia drzwiowe dla domu i ogrodu</h2>
        <p>Dobieramy klamki, okucia budowlane i zabezpieczenia do drzwi, bram oraz mebli. W naszej ofercie znajdziesz produkty polskich producentów i rozwiązania do codziennego użytku.</p>
        {foreach $block.links as $link}
          <a class="btn pd-about-link" href="{$link.url|escape:'html':'UTF-8'}">Czytaj więcej<span class="sr-only"> o firmie</span></a>
        {/foreach}
      </div>
    </section>
  {/foreach}
{else}
  <div class="col-md-6 links pd-footer-links">
    <div class="row">
      {foreach $linkBlocks as $block}
        {assign var='footer_title' value=$block.title}
        {if $footer_title === 'Informacje o sklepie'}{assign var='footer_title' value='Nasza firma'}{/if}
        <div class="col-md-6 wrapper">
          <h3 class="h3 hidden-sm-down">{$footer_title|escape:'html':'UTF-8'}</h3>
          <button type="button" class="title hidden-md-up pd-footer-toggle"
                  data-target="#footer_sub_menu_{$block.id}" data-toggle="collapse"
                  aria-controls="footer_sub_menu_{$block.id}" aria-expanded="false">
            {$footer_title|escape:'html':'UTF-8'}
            <i class="material-icons" aria-hidden="true">expand_more</i>
          </button>
          <ul id="footer_sub_menu_{$block.id}" class="collapse">
            {foreach $block.links as $link}
              <li>
                <a id="{$link.id|escape:'html':'UTF-8'}-{$block.id}"
                   class="{$link.class|escape:'html':'UTF-8'}"
                   href="{$link.url|escape:'html':'UTF-8'}"
                   {if !empty($link.target)}target="{$link.target|escape:'html':'UTF-8'}" rel="noopener"{/if}>
                  {$link.title|escape:'html':'UTF-8'}
                </a>
              </li>
            {/foreach}
          </ul>
        </div>
      {/foreach}
    </div>
  </div>
{/if}
