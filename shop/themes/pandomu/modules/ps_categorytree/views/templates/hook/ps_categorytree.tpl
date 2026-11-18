{* Na podstawie szablonu PrestaShop SA and Contributors, AFL-3.0.
   Adres kategorii i przycisk rozwiń są niezależne. *}
{function name='pandomu_categorytree' nodes=[] depth=0}
  <ul class="category-sub-menu">
    {foreach $nodes as $node}
      <li data-depth="{$depth}">
        <a {if $depth > 0}class="category-sub-link"{/if} href="{$node.link|escape:'html':'UTF-8'}">
          {$node.name|escape:'html':'UTF-8'}
        </a>
        {if $node.children}
          <button type="button" class="navbar-toggler collapse-icons pd-category-toggle"
                  data-toggle="collapse" data-target="#exCollapsingNavbar{$node.id|intval}"
                  aria-controls="exCollapsingNavbar{$node.id|intval}" aria-expanded="false"
                  aria-label="Podkategorie: {$node.name|trim|escape:'html':'UTF-8'}">
            <i class="material-icons add" aria-hidden="true">add</i>
            <i class="material-icons remove" aria-hidden="true">remove</i>
          </button>
          <div class="collapse" id="exCollapsingNavbar{$node.id|intval}">
            {pandomu_categorytree nodes=$node.children depth=$depth+1}
          </div>
        {/if}
      </li>
    {/foreach}
  </ul>
{/function}

<div class="block-categories">
  <ul class="category-top-menu">
    <li><a class="text-uppercase h6" href="{$categories.link|escape:'html':'UTF-8'}">{$categories.name|escape:'html':'UTF-8'}</a></li>
    {if $categories.children}
      <li>{pandomu_categorytree nodes=$categories.children}</li>
    {/if}
  </ul>
</div>
