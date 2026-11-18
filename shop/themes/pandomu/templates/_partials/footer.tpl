{extends file='parent:_partials/footer.tpl'}

{block name='hook_footer_before'}
  {hook h='displayFooterBefore' excl='ps_linklist'}
{/block}

{block name='copyright_link'}
  <span class="pd-copyright">Wszelkie prawa zastrzeżone © {'Y'|date} F.H. PAN DOMU</span>
{/block}
