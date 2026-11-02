<div class="panel">
  <h3><i class="icon-warning-sign"></i> {l s='Matching exception' d='Modules.Bxmpcsv.Admin'} #{$ex.id_match_exception|intval}
    <span class="badge {if $ex.status == 'pending'}badge-warning{elseif $ex.status == 'resolved'}badge-success{else}badge-danger{/if}">{$ex.status|escape:'html':'UTF-8'}</span></h3>
  <div class="row">
    <div class="col-md-5">
      <h4>{l s='Vendor row' d='Modules.Bxmpcsv.Admin'}</h4>
      <p>{$ex.vendor_name|escape:'html':'UTF-8'} — {if $job_url}<a href="{$job_url|escape:'html':'UTF-8'}">job #{$ex.id_import_job|intval}</a>{else}no job{/if}, row {$line|intval}</p>
      <table class="table table-condensed">
        {foreach $row as $k => $v}<tr><th>{$k|escape:'html':'UTF-8'}</th><td>{$v|escape:'html':'UTF-8'}</td></tr>{/foreach}
      </table>
    </div>
    <div class="col-md-7">
      <h4>{l s='Matching result' d='Modules.Bxmpcsv.Admin'}</h4>
      <p><strong>{$match.status|escape:'html':'UTF-8'}</strong> — {$match.reason|escape:'html':'UTF-8'} {if $match.method}(method {$match.method|escape:'html':'UTF-8'}{if $match.score}, score {$match.score|escape:'html':'UTF-8'}{/if}){/if}
        {if $ex.id_product_resolved}<br>{l s='Resolved to product' d='Modules.Bxmpcsv.Admin'} #{$ex.id_product_resolved|intval}{/if}</p>
      <form method="post" action="{$action_url|escape:'html':'UTF-8'}">
        <table class="table">
          <thead><tr><th></th><th>{l s='Product' d='Admin.Global'}</th><th>{l s='Combination' d='Modules.Bxmpcsv.Admin'}</th><th>EAN</th><th>{l s='Reference' d='Admin.Global'}</th><th>{l s='Brand' d='Admin.Global'}</th><th>{l s='Offers' d='Modules.Bxmpcsv.Admin'}</th></tr></thead>
          <tbody>
          {foreach $candidates as $c}
            <tr>
              <td><input type="radio" name="bxmp_candidate" value="{$c.id_product|intval}-{$c.id_product_attribute|intval}" id="cand_{$c.id_product|intval}_{$c.id_product_attribute|intval}"></td>
              <td><label for="cand_{$c.id_product|intval}_{$c.id_product_attribute|intval}">#{$c.id_product|intval} {$c.name|escape:'html':'UTF-8'}</label> <a href="{$c.admin_url|escape:'html':'UTF-8'}" target="_blank"><i class="icon-external-link"></i></a></td>
              <td>{if $c.id_product_attribute}#{$c.id_product_attribute|intval} {$c.combination|escape:'html':'UTF-8'}{else}—{/if}</td>
              <td>{$c.ean13|escape:'html':'UTF-8'}</td>
              <td>{$c.reference|escape:'html':'UTF-8'}</td>
              <td>{$c.brand|escape:'html':'UTF-8'}</td>
              <td>{$c.offers|intval}</td>
            </tr>
          {foreachelse}
            <tr><td colspan="7">{l s='No candidate product.' d='Modules.Bxmpcsv.Admin'}</td></tr>
          {/foreach}
          </tbody>
        </table>
        <div class="form-inline" style="margin-bottom:10px">
          {l s='or product id' d='Modules.Bxmpcsv.Admin'} <input type="text" name="bxmp_manual_product" class="form-control fixed-width-sm" placeholder="id_product">
          {l s='combination id' d='Modules.Bxmpcsv.Admin'} <input type="text" name="bxmp_manual_attribute" class="form-control fixed-width-sm" placeholder="0">
        </div>
        {if $ex.status != 'resolved'}
        <button type="submit" name="bxmpLink" value="1" class="btn btn-primary"><i class="icon-link"></i> {l s='Link to selected product' d='Modules.Bxmpcsv.Admin'}</button>
        <button type="submit" name="bxmpCreate" value="1" class="btn btn-success" onclick="return confirm('Create a new master product from this row?');"><i class="icon-plus"></i> {l s='Create new master product' d='Modules.Bxmpcsv.Admin'}</button>
        <button type="submit" name="bxmpRetry" value="1" class="btn btn-default"><i class="icon-refresh"></i> {l s='Retry matching' d='Modules.Bxmpcsv.Admin'}</button>
        {if $ex.status == 'pending'}<button type="submit" name="bxmpReject" value="1" class="btn btn-danger"><i class="icon-ban"></i> {l s='Reject' d='Modules.Bxmpcsv.Admin'}</button>{/if}
        {/if}
      </form>
    </div>
  </div>
  <p><a class="btn btn-default" href="{$back_url|escape:'html':'UTF-8'}"><i class="icon-arrow-left"></i> {l s='Back to list' d='Modules.Bxmpcsv.Admin'}</a></p>
</div>
