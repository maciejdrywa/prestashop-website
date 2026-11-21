{extends file='parent:catalog/product.tpl'}

{* Na karcie produktu pozostaje wyłącznie zakładka z pełnym opisem. *}
{block name='product_tabs'}
  {if $product.description}
    <div class="tabs">
      <ul class="nav nav-tabs" role="tablist">
        <li class="nav-item">
          <a class="nav-link active js-product-nav-active"
             id="product-description-tab"
             data-toggle="tab"
             href="#description"
             role="tab"
             aria-controls="description"
             aria-selected="true">{l s='Description' d='Shop.Theme.Catalog'}</a>
        </li>
      </ul>
      <div class="tab-content" id="tab-content">
        <div class="tab-pane fade in active js-product-tab-active"
             id="description" role="tabpanel" aria-labelledby="product-description-tab">
          {block name='product_description'}
            <div class="product-description">{$product.description nofilter}</div>
          {/block}
        </div>
      </div>
    </div>
  {/if}
  {block name='product_details'}
    {include file='catalog/_partials/product-details.tpl'}
  {/block}
{/block}

{block name='hook_display_reassurance'}{/block}
