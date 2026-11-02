{extends file='page.tpl'}
{block name='page_title'}{/block}
{block name='page_content'}
<div class="bxmpv bxmpv-denied" data-testid="denied" data-status="{$bxmpv_denied.status|intval}" data-reason="{$bxmpv_denied.reason|escape:'html':'UTF-8'}">
  <div class="bxmpv-denied__card">
    <span class="bxmpv-denied__code">{$bxmpv_denied.status|intval}</span>
    <h1>{if $bxmpv_denied.status == 404}Not found{else}Access denied{/if}</h1>
    <p>{$bxmpv_denied.message|escape:'html':'UTF-8'}</p>
    <p>
      {if $bxmpv.nav|count}<a class="btn btn-primary" href="{$bxmpv.nav[0].url|escape:'html':'UTF-8'}">Back to the vendor portal</a>{/if}
      <a class="btn btn-secondary" href="{$urls.pages.my_account|escape:'html':'UTF-8'}">My account</a>
    </p>
  </div>
</div>
{/block}
