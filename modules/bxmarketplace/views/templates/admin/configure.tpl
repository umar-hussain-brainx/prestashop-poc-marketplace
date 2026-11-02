<div class="panel">
  <div class="panel-heading"><i class="icon-sitemap"></i> {l s='BrainX Marketplace' d='Modules.Bxmarketplace.Admin'}</div>
  <p>
    {foreach from=$bxmp_links key=label item=href}
      <a class="btn btn-default" href="{$href|escape:'html':'UTF-8'}">{$label|escape:'html':'UTF-8'}</a>
    {/foreach}
  </p>
  <p>{l s='Internal API base URL' d='Modules.Bxmarketplace.Admin'}: <code>{$bxmp_api_url|escape:'html':'UTF-8'}?resource=vendors</code>
    &mdash; {l s='send the token as' d='Modules.Bxmarketplace.Admin'} <code>Authorization: Bearer &lt;token&gt;</code> (docs/API.md).</p>
</div>

<div class="panel">
  <div class="panel-heading"><i class="icon-key"></i> {l s='API tokens' d='Modules.Bxmarketplace.Admin'} <span class="badge">{$bxmp_token_count|intval}</span></div>
  <table class="table">
    <thead>
      <tr><th>ID</th><th>{l s='Label' d='Modules.Bxmarketplace.Admin'}</th><th>{l s='Scope' d='Modules.Bxmarketplace.Admin'}</th><th>{l s='Token' d='Modules.Bxmarketplace.Admin'}</th><th>{l s='Active' d='Modules.Bxmarketplace.Admin'}</th><th>{l s='Created' d='Modules.Bxmarketplace.Admin'}</th><th></th></tr>
    </thead>
    <tbody>
    {foreach from=$bxmp_tokens item=t}
      <tr>
        <td>{$t.id_api_token|intval}</td>
        <td>{$t.label|escape:'html':'UTF-8'}</td>
        <td>{if $t.id_vendor}{l s='Vendor' d='Modules.Bxmarketplace.Admin'}: {$t.vendor_name|escape:'html':'UTF-8'}{else}<strong>{l s='Admin' d='Modules.Bxmarketplace.Admin'}</strong>{/if}</td>
        <td><code>{$t.masked|escape:'html':'UTF-8'}</code></td>
        <td>{if $t.active}<i class="icon-check text-success"></i>{else}<i class="icon-remove text-danger"></i>{/if}</td>
        <td>{$t.date_add|escape:'html':'UTF-8'}</td>
        <td>{if $t.active}<a class="btn btn-default btn-xs" href="{$bxmp_revoke_url|escape:'html':'UTF-8'}&amp;id_api_token={$t.id_api_token|intval}" onclick="return confirm('Revoke?');"><i class="icon-trash"></i> {l s='Revoke' d='Modules.Bxmarketplace.Admin'}</a>{/if}</td>
      </tr>
    {foreachelse}
      <tr><td colspan="7">{l s='No token yet.' d='Modules.Bxmarketplace.Admin'}</td></tr>
    {/foreach}
    </tbody>
  </table>
</div>
