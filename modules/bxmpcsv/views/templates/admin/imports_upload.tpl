<div class="row">
  <div class="col-lg-8">
    <form class="panel form-horizontal" method="post" enctype="multipart/form-data" action="{$bxmp_action|escape:'html':'UTF-8'}">
      <h3><i class="icon-upload"></i> {l s='New import' d='Modules.Bxmpcsv.Admin'}</h3>
      <div class="form-group">
        <label class="control-label col-lg-3 required">{l s='Vendor' d='Modules.Bxmpcsv.Admin'}</label>
        <div class="col-lg-6">
          <select name="bxmp_vendor" id="bxmp_vendor">
            <option value="0">{l s='— all vendors (order status only) —' d='Modules.Bxmpcsv.Admin'}</option>
            {foreach $bxmp_vendors as $id => $name}<option value="{$id|intval}">{$name|escape:'html':'UTF-8'}</option>{/foreach}
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-3">{l s='Type' d='Modules.Bxmpcsv.Admin'}</label>
        <div class="col-lg-6">
          <select name="bxmp_type">
            <option value="offers">{l s='Catalog / offers' d='Modules.Bxmpcsv.Admin'}</option>
            <option value="order_status">{l s='Order status update' d='Modules.Bxmpcsv.Admin'}</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-3">{l s='CSV file' d='Modules.Bxmpcsv.Admin'}</label>
        <div class="col-lg-6"><input type="file" name="bxmp_file" accept=".csv,.txt,.tsv,text/csv"></div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-3">{l s='or URL' d='Modules.Bxmpcsv.Admin'}</label>
        <div class="col-lg-6"><input type="text" name="bxmp_url" placeholder="https://vendor.example/feed.csv"></div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-3">{l s='or server path' d='Modules.Bxmpcsv.Admin'}</label>
        <div class="col-lg-6"><input type="text" name="bxmp_path" placeholder="vendor-a.csv">
          <p class="help-block">{l s='Relative to' d='Modules.Bxmpcsv.Admin'} {$bxmp_feeds_dir|escape:'html':'UTF-8'}</p></div>
      </div>
      <div class="form-group">
        <div class="col-lg-9 col-lg-offset-3">
          <label class="checkbox-inline"><input type="checkbox" name="bxmp_full_sync" value="1"{if $bxmp_full_sync} checked{/if}> {l s='Full sync (offers missing from the file → stock 0)' d='Modules.Bxmpcsv.Admin'}</label><br>
          <label class="checkbox-inline"><input type="checkbox" name="bxmp_auto_create" value="1"{if $bxmp_auto_create} checked{/if}> {l s='Create master products for unmatched rows' d='Modules.Bxmpcsv.Admin'}</label><br>
          <label class="checkbox-inline"><input type="checkbox" name="bxmp_process_now" value="1"> {l s='Process now (else queued for the worker)' d='Modules.Bxmpcsv.Admin'}</label>
        </div>
      </div>
      <div class="panel-footer">
        <button type="submit" name="submitBxmpcsvUpload" value="1" class="btn btn-default pull-right"><i class="process-icon-upload"></i> {l s='Enqueue import' d='Modules.Bxmpcsv.Admin'}</button>
      </div>
    </form>
  </div>
  <div class="col-lg-4">
    <div class="panel">
      <h3><i class="icon-info"></i> {l s='Queue' d='Modules.Bxmpcsv.Admin'}</h3>
      <p>{l s='Jobs waiting / running:' d='Modules.Bxmpcsv.Admin'} <strong>{$bxmp_queue|intval}</strong></p>
      <p>{l s='Pending matching exceptions:' d='Modules.Bxmpcsv.Admin'} <a href="{$bxmp_exceptions_url|escape:'html':'UTF-8'}"><strong>{$bxmp_pending_exceptions|intval}</strong></a></p>
      <p><a href="{$bxmp_template_url|escape:'html':'UTF-8'}"><i class="icon-download"></i> {l s='Offer CSV template' d='Modules.Bxmpcsv.Admin'}</a><br>
         <a href="{$bxmp_status_template_url|escape:'html':'UTF-8'}"><i class="icon-download"></i> {l s='Order status CSV template' d='Modules.Bxmpcsv.Admin'}</a></p>
      <p class="help-block">{l s='Webcron URL (every minute):' d='Modules.Bxmpcsv.Admin'}<br><code style="word-break:break-all">{$bxmp_cron_url|escape:'html':'UTF-8'}</code></p>
    </div>
  </div>
</div>
