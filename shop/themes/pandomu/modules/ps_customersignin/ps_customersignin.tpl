{* Zachowujemy adres powrotu po logowaniu i odnośnik wylogowania z modułu Classic.
   Na podstawie szablonu PrestaShop SA and Contributors, AFL-3.0. *}
<div id="_desktop_user_info">
  <div class="user-info">
    {if $logged}
      <a class="logout hidden-sm-down" href="{$urls.actions.logout}" rel="nofollow">
        {l s='Sign out' d='Shop.Theme.Actions'}
      </a>
    {/if}
    <a class="account pd-header-action" rel="nofollow" aria-label="Konto"
       href="{if $logged}{$urls.pages.my_account}{else}{$urls.pages.authentication}?back={$urls.current_url|urlencode}{/if}">
      <i class="material-icons" aria-hidden="true">account_circle</i>
      <span class="hidden-sm-down">Konto</span>
    </a>
  </div>
</div>
