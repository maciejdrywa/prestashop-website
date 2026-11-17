{extends file='parent:layouts/layout-both-columns.tpl'}

{block name='left_column'}
  {if $page.page_name === 'category'}
    <div id="left-column" class="col-xs-12 col-md-4 col-lg-3">
      {hook h='displayLeftColumn' excl='ps_brandlist,ps_supplierlist'}
    </div>
  {else}
    {$smarty.block.parent}
  {/if}
{/block}

{block name='footer'}
  {hook h='displayFooterBefore' mod='ps_linklist'}
  <div class="container pd-footer-help">
    <h2>Masz pytania?</h2>
    <p>Chętnie pomożemy w doborze produktów i odpowiemy na pytania o ofertę.</p>
  </div>
  {$smarty.block.parent}
{/block}
