{if $page.page_name === 'category'}
  {* Po każdym odświeżeniu AJAX PrestaShop wyznacza aktywną opcję i linki z aktualnymi filtrami. *}
  {assign var='selected_label' value=$listing.sort_selected}
  {foreach $listing.sort_orders as $sort_order}
    {if $sort_order.current && $sort_order.urlParameter === 'product.position.asc'}
      {assign var='selected_label' value='Trafność'}
    {/if}
  {/foreach}

  <span class="col-sm-3 col-md-3 hidden-sm-down sort-by">{l s='Sort by:' d='Shop.Theme.Global'}</span>
  <div class="{if !empty($listing.rendered_facets)}col-xs-8{else}col-xs-12{/if} col-sm-9 col-md-9 products-sort-order dropdown">
    <button type="button" class="btn-unstyle select-title" data-toggle="dropdown"
            aria-label="{l s='Sort by:' d='Shop.Theme.Global'} {$selected_label|escape:'html':'UTF-8'}"
            aria-haspopup="true" aria-expanded="false">
      <span>{if $selected_label}{$selected_label|escape:'html':'UTF-8'}{else}{l s='Choose' d='Shop.Theme.Actions'}{/if}</span>
      <i class="material-icons" aria-hidden="true">arrow_drop_down</i>
    </button>
    {assign var='category_sort_options' value=['product.sales.desc', 'product.position.asc', 'product.name.asc',
                                             'product.name.desc', 'product.price.asc', 'product.price.desc']}
    <div class="dropdown-menu">
      {foreach $listing.sort_orders as $sort_order}
        {if in_array($sort_order.urlParameter, $category_sort_options, true)}
          <a rel="nofollow" href="{$sort_order.url|escape:'html':'UTF-8'}"
             class="select-list js-search-link{if $sort_order.current} current{/if}"{if $sort_order.current} aria-current="true"{/if}>
            {if $sort_order.urlParameter === 'product.position.asc'}Trafność{else}{$sort_order.label|escape:'html':'UTF-8'}{/if}
          </a>
        {/if}
      {/foreach}
    </div>
  </div>
{else}
  {include file='parent:catalog/_partials/sort-orders.tpl'}
{/if}
