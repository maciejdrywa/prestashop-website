{* Zachowujemy identyfikatory i selektory wymagane przez autouzupełnianie ps_searchbar.
   Na podstawie szablonu PrestaShop SA and Contributors, AFL-3.0. *}
<div id="search_widget" class="search-widgets" data-search-controller-url="{$search_controller_url|escape:'html':'UTF-8'}">
  <form method="get" action="{$search_controller_url|escape:'html':'UTF-8'}">
    <input type="hidden" name="controller" value="search">
    <input type="text" name="s" value="{$search_string|escape:'html':'UTF-8'}"
           placeholder="{l s='Search our catalog' d='Shop.Theme.Catalog'}"
           aria-label="{l s='Search' d='Shop.Theme.Catalog'}">
    <i class="material-icons clear" aria-hidden="true">clear</i>
    <button type="submit" aria-label="{l s='Search' d='Shop.Theme.Catalog'}">
      <span class="material-icons" aria-hidden="true">search</span>
      <span class="pd-search-caption" aria-hidden="true">{l s='Search' d='Shop.Theme.Catalog'}</span>
    </button>
  </form>
</div>
