{* Moduł koszyka odświeża ten sam szablon przez AJAX. Zachowujemy klasy podglądu i adres odświeżania.
   Na podstawie szablonu PrestaShop SA and Contributors, AFL-3.0. *}
<div id="_desktop_cart">
  <div class="blockcart cart-preview {if $cart.products_count > 0}active{else}inactive{/if}" data-refresh-url="{$refresh_url}">
    <div class="header">
      <a class="pd-header-action" rel="nofollow" href="{$cart_url}"
         aria-label="{l s='Shopping cart link containing %nbProducts% product(s)' sprintf=['%nbProducts%' => $cart.products_count] d='Shop.Theme.Checkout'}">
        <i class="material-icons shopping-cart" aria-hidden="true">shopping_cart</i>
        <span class="hidden-sm-down">{l s='Cart' d='Shop.Theme.Checkout'}</span>
        <span class="cart-products-count" aria-hidden="true">{$cart.products_count}</span>
      </a>
    </div>
  </div>
</div>
