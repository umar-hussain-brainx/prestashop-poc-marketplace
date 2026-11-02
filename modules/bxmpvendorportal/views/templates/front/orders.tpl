{extends file='module:bxmpvendorportal/views/templates/front/_portal.tpl'}
{block name='portal_title'}Orders{/block}
{block name='portal_actions'}
  {if $bxmpv.can.csv_export_orders}<a class="btn btn-secondary" href="{$ord.export_url|escape:'html':'UTF-8'}" data-testid="export-orders"><i class="material-icons">download</i> Export CSV</a>{/if}
{/block}

{block name='portal_content'}
  <form class="bxmpv-filters" method="get" action="{$ord.base_url|escape:'html':'UTF-8'}" data-testid="order-filters">
    <label class="bxmpv-grow">Search <input type="search" name="q" class="form-control" value="{$ord.filters.q|escape:'html':'UTF-8'}" placeholder="Reference, tracking, order #"></label>
    <label>Status
      <select name="status" class="form-control" data-autosubmit>
        <option value="">All</option>
        {foreach $ord.statuses as $s}<option value="{$s|escape:'html':'UTF-8'}"{if $ord.filters.status == $s} selected{/if}>{$s|escape:'html':'UTF-8'}</option>{/foreach}
      </select>
    </label>
    <label>From <input type="date" name="date_from" class="form-control" value="{$ord.filters.date_from|escape:'html':'UTF-8'}"></label>
    <label>To <input type="date" name="date_to" class="form-control" value="{$ord.filters.date_to|escape:'html':'UTF-8'}"></label>
    <button class="btn btn-secondary" type="submit">Filter</button>
  </form>

  <div class="bxmpv-table-wrap">
    <table class="bxmpv-table bxmpv-table--stack" data-testid="orders-table">
      <thead><tr><th>Reference</th><th>Date</th><th>Status</th><th class="num">Items</th><th class="num">Total paid</th><th class="num">Commission</th><th class="num">Your payout</th><th>Shipment</th><th></th></tr></thead>
      <tbody>
      {foreach $ord.rows as $o}
        <tr data-vendor-order="{$o.id_vendor_order|intval}" data-status="{$o.status|escape:'html':'UTF-8'}">
          <td data-label="Reference"><span class="bxmpv-strong">{$o.reference|escape:'html':'UTF-8'}</span><br><small class="bxmpv-muted">#{$o.id_vendor_order|intval}</small></td>
          <td data-label="Date">{$o.date_add|truncate:16:''|escape:'html':'UTF-8'}</td>
          <td data-label="Status"><span class="bxmpv-pill bxmpv-pill--{$o.status|escape:'html':'UTF-8'}">{$o.status|escape:'html':'UTF-8'}</span></td>
          <td data-label="Items" class="num">{$o.items|intval}</td>
          <td data-label="Total paid" class="num">{$o.total_paid|string_format:'%.2f'}</td>
          <td data-label="Commission" class="num">{$o.commission_amount|string_format:'%.2f'}</td>
          <td data-label="Payout" class="num">{$o.vendor_payout|string_format:'%.2f'}</td>
          <td data-label="Shipment">{if $o.tracking_number}{$o.carrier_name|escape:'html':'UTF-8'}<br><small><code>{$o.tracking_number|escape:'html':'UTF-8'}</code></small>{else}—{/if}</td>
          <td class="bxmpv-actions"><a class="btn btn-secondary btn-sm" href="{$link->getModuleLink('bxmpvendorportal', 'orders', ['id' => $o.id_vendor_order])|escape:'html':'UTF-8'}" data-testid="order-open">Open</a></td>
        </tr>
      {foreachelse}
        <tr><td colspan="9" class="bxmpv-empty">No orders found.</td></tr>
      {/foreach}
      </tbody>
    </table>
  </div>
  {include file='module:bxmpvendorportal/views/templates/front/_partials/pagination.tpl' pg=$ord.pagination}
{/block}
