{extends file='parent:_partials/header.tpl'}

{block name='header_nav'}
  <div class="header-nav pd-contact-strip">
    <div class="container">{hook h='displayNav1'}</div>
  </div>
{/block}

{block name='header_top'}
  <div class="header-top pd-header">
    <div class="container pd-header-grid">
      <button type="button" id="menu-icon" class="pd-menu-toggle hidden-md-up"
              aria-label="Menu kategorii" aria-controls="mobile_top_menu_wrapper" aria-expanded="false">
        <i class="material-icons" aria-hidden="true">menu</i>
      </button>

      {* Classic przenosi zawartość między parami identyfikatorów desktop i mobile. *}
      <div id="_desktop_logo" class="pd-logo hidden-sm-down">
        <a href="{$urls.base_url}">
          <img src="{$urls.child_img_url}logo.jpg" width="312" height="80" alt="PAN DOMU – Wszystko dla Twojego domu">
        </a>
      </div>
      <div id="_mobile_logo" class="pd-logo hidden-md-up"></div>

      <div class="pd-search">{hook h='displayTop' mod='ps_searchbar'}</div>

      <div class="pd-header-actions hidden-sm-down">{hook h='displayNav2'}</div>
      <div class="pd-header-actions hidden-md-up">
        <div id="_mobile_user_info"></div>
        <div id="_mobile_cart"></div>
      </div>

      <nav id="mobile_top_menu_wrapper" class="hidden-md-up pd-mobile-navigation"
           aria-label="Kategorie produktów" style="display:none;">
        <div class="js-top-menu mobile" id="_mobile_top_menu"></div>
        <div class="js-top-menu-bottom">
          <div id="_mobile_currency_selector"></div>
          <div id="_mobile_language_selector"></div>
          <div id="_mobile_contact_link"></div>
        </div>
      </nav>
    </div>
  </div>

  <nav class="pd-navigation hidden-sm-down" aria-label="Kategorie produktów">
    <div class="container">{hook h='displayTop' excl='ps_searchbar'}</div>
  </nav>
  {hook h='displayNavFullWidth'}
{/block}
