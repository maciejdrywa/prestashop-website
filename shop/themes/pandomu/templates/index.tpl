{extends file='parent:index.tpl'}

{block name='page_content_top'}
  {$smarty.block.parent}
  <span hidden data-pandomu-theme></span>
{/block}

{block name='hook_home'}
  {* Kafle mają osobne miejsce względem list produktowych z displayHome. *}
  {hook h='displayHome' mod='ps_linklist'}
  {hook h='displayHome' excl='ps_linklist,ps_specials,ps_newproducts,ps_bestsellers'}
{/block}
