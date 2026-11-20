{extends file='parent:catalog/_partials/product-cover-thumbnails.tpl'}

{block name='product_cover'}
  {if $product.default_image}
    {$smarty.block.parent}
  {else}
    {* Placeholder Classic także otrzymuje opis, gdy produkt nie ma galerii. *}
    <div class="product-cover">
      <img class="img-fluid" src="{$urls.no_picture_image.bySize.large_default.url}"
           width="{$urls.no_picture_image.bySize.large_default.width}"
           height="{$urls.no_picture_image.bySize.large_default.height}"
           alt="Brak zdjęcia produktu — {$product.name|escape:'html':'UTF-8'}" loading="lazy">
    </div>
  {/if}
{/block}
