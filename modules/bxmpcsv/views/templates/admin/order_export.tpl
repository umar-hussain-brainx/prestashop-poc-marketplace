<div class="row">
  <div class="col-lg-6">
    <form class="panel form-horizontal" method="post" action="{$bxmp_action|escape:'html':'UTF-8'}">
      <h3><i class="icon-download"></i> {l s='Export vendor orders (CSV)' d='Modules.Bxmpcsv.Admin'}</h3>
      <div class="form-group">
        <label class="control-label col-lg-4">{l s='Vendor' d='Modules.Bxmpcsv.Admin'}</label>
        <div class="col-lg-8"><select name="id_vendor"><option value="0">{l s='All vendors' d='Modules.Bxmpcsv.Admin'}</option>
          {foreach $bxmp_vendors as $id => $name}<option value="{$id|intval}">{$name|escape:'html':'UTF-8'}</option>{/foreach}</select></div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-4">{l s='From / to' d='Modules.Bxmpcsv.Admin'}</label>
        <div class="col-lg-4"><input type="date" name="date_from" value="{$bxmp_date_from|escape:'html':'UTF-8'}"></div>
        <div class="col-lg-4"><input type="date" name="date_to" value="{$bxmp_date_to|escape:'html':'UTF-8'}"></div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-4">{l s='Status' d='Modules.Bxmpcsv.Admin'}</label>
        <div class="col-lg-8">{foreach $bxmp_statuses as $s}<label class="checkbox-inline"><input type="checkbox" name="status[]" value="{$s|escape:'html':'UTF-8'}"> {$s|escape:'html':'UTF-8'}</label> {/foreach}
          <p class="help-block">{l s='None checked = all statuses.' d='Modules.Bxmpcsv.Admin'}</p></div>
      </div>
      <div class="panel-footer"><button type="submit" name="submitBxmpOrderExport" value="1" class="btn btn-default pull-right"><i class="process-icon-download"></i> {l s='Download CSV' d='Modules.Bxmpcsv.Admin'}</button></div>
    </form>
  </div>
  <div class="col-lg-6">
    <form class="panel form-horizontal" method="post" enctype="multipart/form-data" action="{$bxmp_action|escape:'html':'UTF-8'}">
      <h3><i class="icon-upload"></i> {l s='Import order status updates (CSV)' d='Modules.Bxmpcsv.Admin'}</h3>
      <p>{l s='Columns: vendor_order_reference, status, tracking_number, carrier_name — the export file itself can be re-imported.' d='Modules.Bxmpcsv.Admin'} <a href="{$bxmp_template_url|escape:'html':'UTF-8'}">{l s='Template' d='Modules.Bxmpcsv.Admin'}</a></p>
      <div class="form-group">
        <label class="control-label col-lg-4">{l s='Vendor scope' d='Modules.Bxmpcsv.Admin'}</label>
        <div class="col-lg-8"><select name="id_vendor_status"><option value="0">{l s='All vendors' d='Modules.Bxmpcsv.Admin'}</option>
          {foreach $bxmp_vendors as $id => $name}<option value="{$id|intval}">{$name|escape:'html':'UTF-8'}</option>{/foreach}</select></div>
      </div>
      <div class="form-group">
        <label class="control-label col-lg-4">{l s='CSV file' d='Modules.Bxmpcsv.Admin'}</label>
        <div class="col-lg-8"><input type="file" name="status_file" accept=".csv,.txt,text/csv"></div>
      </div>
      <div class="panel-footer"><button type="submit" name="submitBxmpStatusImport" value="1" class="btn btn-default pull-right"><i class="process-icon-upload"></i> {l s='Import' d='Modules.Bxmpcsv.Admin'}</button></div>
    </form>
    <div class="panel">
      <h3>{l s='Vendor orders' d='Modules.Bxmpcsv.Admin'}</h3>
      <table class="table"><thead><tr><th>{l s='Vendor' d='Modules.Bxmpcsv.Admin'}</th>{foreach $bxmp_statuses as $s}<th>{$s|escape:'html':'UTF-8'}</th>{/foreach}</tr></thead>
      <tbody>{foreach $bxmp_summary as $vendor => $c}<tr><td>{$vendor|escape:'html':'UTF-8'}</td>{foreach $bxmp_statuses as $s}<td>{if isset($c[$s])}{$c[$s]|intval}{else}0{/if}</td>{/foreach}</tr>{/foreach}</tbody></table>
    </div>
  </div>
</div>
