{**
 * Vendor portal layout (extends the theme page so it works with any theme, no theme file edited).
 *}
{extends file='page.tpl'}

{block name='page_title'}{/block}

{block name='page_content'}
<div class="bxmpv" id="bxmpv-{$bxmpv.page|escape:'html':'UTF-8'}" data-csrf="{$bxmpv.csrf|escape:'html':'UTF-8'}">
  <div class="bxmpv-shell">
    <aside class="bxmpv-side" aria-label="Vendor portal navigation">
      <div class="bxmpv-brand">
        <span class="bxmpv-brand__label">Vendor portal</span>
        <strong class="bxmpv-brand__name">{$bxmpv.vendor_name|escape:'html':'UTF-8'}</strong>
        <span class="bxmpv-role bxmpv-role--{$bxmpv.role|escape:'html':'UTF-8'}" data-testid="role">{$bxmpv.role|escape:'html':'UTF-8'}</span>
      </div>
      <nav class="bxmpv-nav">
        {foreach $bxmpv.nav as $item}
          <a class="bxmpv-nav__link{if $item.current} is-current{/if}" href="{$item.url|escape:'html':'UTF-8'}" data-page="{$item.controller|escape:'html':'UTF-8'}"{if $item.current} aria-current="page"{/if}>
            <i class="material-icons" aria-hidden="true">{$item.icon|escape:'html':'UTF-8'}</i><span>{$item.label|escape:'html':'UTF-8'}</span>
          </a>
        {/foreach}
      </nav>
      <p class="bxmpv-side__who">Signed in as {$bxmpv.customer_name|escape:'html':'UTF-8'}</p>
    </aside>
    <section class="bxmpv-main">
      <header class="bxmpv-head">
        <h1 class="bxmpv-title">{block name='portal_title'}Vendor portal{/block}</h1>
        <div class="bxmpv-head__actions">{block name='portal_actions'}{/block}</div>
      </header>
      {block name='portal_content'}{/block}
    </section>
  </div>
</div>
{/block}
