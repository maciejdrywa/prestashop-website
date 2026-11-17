{extends file='parent:index.tpl'}

{block name='page_content_top'}
  {$smarty.block.parent}
  {* Ukryty znacznik potwierdza, że PrestaShop renderuje rozszerzenie motywu Classic. *}
  <span hidden data-pandomu-theme></span>
{/block}
