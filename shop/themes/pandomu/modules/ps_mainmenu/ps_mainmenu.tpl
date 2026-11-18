{*
 * Renderuje drzewo z ps_mainmenu. Przycisk rozwijania i link kategorii są odrębnymi elementami.
 * Klasy i identyfikatory zachowują przenoszenie menu do widoku mobilnego przez Classic.
 * Oparte na szablonie PrestaShop Classic, dostępnym na licencji AFL-3.0.
 * Copyright PrestaShop SA and Contributors.
 *}
{function name='pandomu_menu' nodes=[] depth=0 prefix='pd-menu'}
  {if $nodes|count}
    <ul class="top-menu" data-depth="{$depth}"{if $depth === 0} id="top-menu"{/if}>
      {foreach $nodes as $node}
        {assign var='submenu_id' value="{$prefix}-{$node@iteration}"}
        <li class="{$node.type|escape:'html':'UTF-8'}{if $node.current} current{/if}">
          <div class="pd-menu-item">
            <a class="dropdown-item{if $depth === 1} dropdown-submenu{/if}"
               href="{$node.url|escape:'html':'UTF-8'}" data-depth="{$depth}"
               {if $node.open_in_new_window}target="_blank" rel="noopener"{/if}>
              {$node.label|escape:'html':'UTF-8'}
            </a>
            {if $node.children|count}
              <button type="button" class="pd-submenu-toggle" data-toggle="collapse"
                      data-target="#{$submenu_id}" aria-controls="{$submenu_id}" aria-expanded="false"
                      aria-label="Podkategorie: {$node.label|escape:'html':'UTF-8'}">
                <i class="material-icons" aria-hidden="true">expand_more</i>
              </button>
            {/if}
          </div>
          {if $node.children|count}
            <div id="{$submenu_id}" class="collapse{if $depth === 0} popover sub-menu js-sub-menu{/if}">
              {pandomu_menu nodes=$node.children depth=$node.depth prefix=$submenu_id}
            </div>
          {/if}
        </li>
      {/foreach}
    </ul>
  {/if}
{/function}

<div class="menu js-top-menu position-static hidden-sm-down" id="_desktop_top_menu">
  {pandomu_menu nodes=$menu.children}
</div>
