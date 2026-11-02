<div class="panel">
  <h3><i class="icon-time"></i> {l s='Asynchronous worker / scheduled feeds' d='Modules.Bxmpcsv.Admin'}</h3>
  <p>{l s='Imports are queued and processed in chunks by a worker. Run it every minute with one of:' d='Modules.Bxmpcsv.Admin'}</p>
  <p><strong>Crontab (CLI, recommended)</strong></p>
  <pre>* * * * * www-data {$bxmpcsv_cli|escape:'html':'UTF-8'} --quiet</pre>
  <p><strong>Webcron (HTTP)</strong></p>
  <pre>* * * * * curl -fsS "{$bxmpcsv_cron_url|escape:'html':'UTF-8'}" &gt;/dev/null</pre>
  <p><a class="btn btn-default" href="{$bxmpcsv_regen_url|escape:'html':'UTF-8'}" onclick="return confirm('Regenerate the cron token?');"><i class="icon-refresh"></i> {l s='Regenerate cron token' d='Modules.Bxmpcsv.Admin'}</a>
  {foreach $bxmpcsv_links as $label => $url}
    <a class="btn btn-default" href="{$url|escape:'html':'UTF-8'}">{$label|escape:'html':'UTF-8'}</a>
  {/foreach}
  </p>
</div>
