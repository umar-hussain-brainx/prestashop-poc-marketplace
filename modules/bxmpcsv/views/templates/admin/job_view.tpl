<div class="panel">
  <h3><i class="icon-file-text"></i> {l s='Import job' d='Modules.Bxmpcsv.Admin'} #{$job.id_import_job|intval}
    <span class="badge {if $job.status == 'done'}badge-success{elseif $job.status == 'failed'}badge-danger{elseif $job.status == 'running'}badge-warning{else}badge-info{/if}">{$job.status|escape:'html':'UTF-8'}</span></h3>
  <div class="row">
    <div class="col-md-6">
      <table class="table">
        <tr><th>{l s='Vendor' d='Modules.Bxmpcsv.Admin'}</th><td>{if $job.vendor_name}{$job.vendor_name|escape:'html':'UTF-8'}{else}(all vendors){/if} (#{$job.id_vendor|intval})</td></tr>
        <tr><th>{l s='Type' d='Modules.Bxmpcsv.Admin'}</th><td>{$job.type|escape:'html':'UTF-8'}</td></tr>
        <tr><th>{l s='Source' d='Modules.Bxmpcsv.Admin'}</th><td>{$job.source|escape:'html':'UTF-8'} — {$job.original_name|escape:'html':'UTF-8'}<br><small>{$job.file_path|escape:'html':'UTF-8'}</small></td></tr>
        <tr><th>{l s='Options' d='Modules.Bxmpcsv.Admin'}</th><td>{foreach $job.options as $k => $v}{$k|escape:'html':'UTF-8'}={if is_bool($v)}{if $v}1{else}0{/if}{else}{$v|escape:'html':'UTF-8'}{/if} {foreachelse}defaults{/foreach}</td></tr>
        <tr><th>{l s='Dates' d='Modules.Bxmpcsv.Admin'}</th><td>queued {$job.date_add|escape:'html':'UTF-8'}<br>started {$job.date_start|escape:'html':'UTF-8'}<br>finished {$job.date_end|escape:'html':'UTF-8'}</td></tr>
      </table>
    </div>
    <div class="col-md-6">
      <table class="table">
        <tr><th>{l s='Rows' d='Modules.Bxmpcsv.Admin'}</th><td>{$job.processed|intval} / {$job.rows_total|intval} processed — <strong>{$job.rows_ok|intval}</strong> ok, <strong>{$job.rows_error|intval}</strong> errors</td></tr>
        {foreach $job.stats as $k => $v}<tr><th>{$k|escape:'html':'UTF-8'}</th><td>{$v|intval}</td></tr>{/foreach}
        <tr><th>{l s='Processing time' d='Modules.Bxmpcsv.Admin'}</th><td>{$job.seconds|escape:'html':'UTF-8'} s</td></tr>
      </table>
    </div>
  </div>
  <p>
    <a class="btn btn-default" href="{$back_url|escape:'html':'UTF-8'}"><i class="icon-arrow-left"></i> {l s='Back' d='Admin.Actions'}</a>
    {if $job.has_error_report}<a class="btn btn-default" href="{$errors_url|escape:'html':'UTF-8'}"><i class="icon-download"></i> {l s='Row report (errors / warnings) CSV' d='Modules.Bxmpcsv.Admin'}</a>{/if}
    {if $exception_count}<a class="btn btn-warning" href="{$exceptions_url|escape:'html':'UTF-8'}"><i class="icon-warning-sign"></i> {$exception_count|intval} {l s='matching exception(s)' d='Modules.Bxmpcsv.Admin'}</a>{/if}
    {if $job.status == 'queued' || $job.status == 'running'}<a class="btn btn-primary" href="{$run_url|escape:'html':'UTF-8'}"><i class="icon-play"></i> {l s='Process now' d='Modules.Bxmpcsv.Admin'}</a>{/if}
  </p>
  <h4>{l s='Log' d='Modules.Bxmpcsv.Admin'}</h4>
  <pre style="max-height:480px;overflow:auto;white-space:pre-wrap">{$job.log|escape:'html':'UTF-8'}</pre>
</div>
