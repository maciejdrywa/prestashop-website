{extends file='parent:catalog/listing/category.tpl'}

{block name='product_list_header'}
  {* Nazwa strony pozostaje dostępna dla czytników ekranu. Nawigację pokazuje boczne drzewo. *}
  <h1 class="sr-only">{$category.name|escape:'html':'UTF-8'}</h1>
{/block}

{block name='subcategory_list'}{/block}
