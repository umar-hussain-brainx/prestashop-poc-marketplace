{extends file='module:bxmpvendorportal/views/templates/front/_portal.tpl'}
{block name='portal_title'}CSV import / export{/block}

{block name='portal_content'}
  {if !$csv.available}
    <div class="bxmpv-card" data-testid="csv-unavailable">
      <p>The CSV import service (module <code>bxmpcsv</code>) is not installed or not enabled on this marketplace yet.</p>
      <p>You can still <a href="{$csv.fallback_export_url|escape:'html':'UTF-8'}">download your orders as CSV</a> and edit offers / stock from the Offers page.</p>
    </div>
  {else}
    <div class="bxmpv-grid2">
      {if $bxmpv.can.csv_import || $bxmpv.can.orders_manage}
      <div class="bxmpv-card" data-testid="csv-upload">
        <div class="bxmpv-card__head"><h2>Upload a CSV</h2></div>
        <form method="post" action="{$csv.base_url|escape:'html':'UTF-8'}" enctype="multipart/form-data" class="bxmpv-form">
          <input type="hidden" name="csrf_token" value="{$bxmpv.csrf|escape:'html':'UTF-8'}">
          <input type="hidden" name="action" value="upload">
          <label>Type
            <select name="type" class="form-control">
              {if $bxmpv.can.csv_import}<option value="offers">Catalog / offers (price, stock, EAN…)</option>{/if}
              {if $bxmpv.can.orders_manage}<option value="order_status">Order status (tracking, carrier)</option>{/if}
            </select>
          </label>
          <label>File <input type="file" name="csv" class="form-control" accept=".csv,.txt,.tsv,text/csv" required></label>
          {if $bxmpv.can.csv_import}<label class="bxmpv-check"><input type="checkbox" name="full_sync" value="1"> Full sync (offers missing from the file get stock 0)</label>{/if}
          <button class="btn btn-primary" type="submit">Upload &amp; import</button>
          <p class="bxmpv-muted">Templates:
            <a href="{$link->getModuleLink('bxmpvendorportal', 'csv', ['action' => 'template', 'type' => 'offers'])|escape:'html':'UTF-8'}">offers</a> ·
            <a href="{$link->getModuleLink('bxmpvendorportal', 'csv', ['action' => 'template', 'type' => 'order_status'])|escape:'html':'UTF-8'}">order status</a></p>
        </form>
      </div>
      {/if}
      <div class="bxmpv-card" data-testid="csv-export">
        <div class="bxmpv-card__head"><h2>Export your orders</h2></div>
        <form method="get" action="{$csv.base_url|escape:'html':'UTF-8'}" class="bxmpv-form">
          <input type="hidden" name="action" value="export_orders">
          <label>From <input type="date" name="date_from" class="form-control"></label>
          <label>To <input type="date" name="date_to" class="form-control"></label>
          <label>Status <select name="status" class="form-control"><option value="">All</option>{foreach $csv.statuses as $s}<option value="{$s|escape:'html':'UTF-8'}">{$s|escape:'html':'UTF-8'}</option>{/foreach}</select></label>
          <button class="btn btn-primary" type="submit">Download CSV</button>
          <p class="bxmpv-muted">One line per order line with delivery data; can be edited and re-imported as an order-status CSV.</p>
        </form>
      </div>
    </div>

    {if $csv.job}
      <div class="bxmpv-card" data-testid="csv-job">
        <div class="bxmpv-card__head"><h2>Import job #{$csv.job.id_import_job|intval}</h2><span class="bxmpv-pill bxmpv-pill--{$csv.job.status|escape:'html':'UTF-8'}">{$csv.job.status|escape:'html':'UTF-8'}</span></div>
        <dl class="bxmpv-dl">
          <dt>File</dt><dd>{$csv.job.original_name|escape:'html':'UTF-8'}</dd>
          <dt>Rows</dt><dd>{$csv.job.rows_total|intval} total · {$csv.job.rows_ok|intval} OK · {$csv.job.rows_error|intval} errors</dd>
          {foreach $csv.job.stats as $k => $n}<dt>{$k|escape:'html':'UTF-8'}</dt><dd>{$n|escape:'html':'UTF-8'}</dd>{/foreach}
        </dl>
        {if $csv.job.log}<pre class="bxmpv-log">{$csv.job.log|escape:'html':'UTF-8'}</pre>{/if}
      </div>
    {/if}

    <div class="bxmpv-table-wrap">
      <table class="bxmpv-table bxmpv-table--stack" data-testid="csv-jobs">
        <thead><tr><th>Job</th><th>Type</th><th>Status</th><th class="num">Rows</th><th class="num">OK</th><th class="num">Errors</th><th>Date</th><th></th></tr></thead>
        <tbody>
        {foreach $csv.jobs as $j}
          <tr>
            <td data-label="Job">#{$j.id_import_job|intval}</td>
            <td data-label="Type">{$j.type|escape:'html':'UTF-8'} <small class="bxmpv-muted">({$j.source|escape:'html':'UTF-8'})</small></td>
            <td data-label="Status"><span class="bxmpv-pill bxmpv-pill--{$j.status|escape:'html':'UTF-8'}">{$j.status|escape:'html':'UTF-8'}</span></td>
            <td data-label="Rows" class="num">{$j.rows_total|intval}</td>
            <td data-label="OK" class="num">{$j.rows_ok|intval}</td>
            <td data-label="Errors" class="num">{$j.rows_error|intval}</td>
            <td data-label="Date">{$j.date_add|escape:'html':'UTF-8'}</td>
            <td class="bxmpv-actions">
              <a class="btn btn-secondary btn-sm" href="{$link->getModuleLink('bxmpvendorportal', 'csv', ['job' => $j.id_import_job])|escape:'html':'UTF-8'}">Details</a>
              {if $j.rows_error > 0}<a class="btn btn-link btn-sm" href="{$link->getModuleLink('bxmpvendorportal', 'csv', ['action' => 'errors', 'id_job' => $j.id_import_job])|escape:'html':'UTF-8'}">Error report</a>{/if}
            </td>
          </tr>
        {foreachelse}
          <tr><td colspan="8" class="bxmpv-empty">No import yet.</td></tr>
        {/foreach}
        </tbody>
      </table>
    </div>
  {/if}
{/block}
