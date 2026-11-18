{* Zachowujemy numer skonfigurowany w sklepie. W instalacji demonstracyjnej wyświetlamy numer z referencji.
   Na podstawie szablonu PrestaShop SA and Contributors, AFL-3.0. *}
<div id="_desktop_contact_link">
  <div id="contact-link">
    Zadzwoń do nas:
    {if $contact_infos.phone}
      <a href="tel:{$contact_infos.phone|replace:' ':''|escape:'html':'UTF-8'}">{$contact_infos.phone|escape:'html':'UTF-8'}</a>
    {else}
      <span>691 489 356</span>
    {/if}
  </div>
</div>
