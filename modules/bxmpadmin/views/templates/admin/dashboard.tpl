<div class="bxmp-dashboard" id="bxmp-dashboard">
  <div class="panel">
    <div class="panel-heading"><i class="icon-dashboard"></i> Marketplace KPIs{if $vendor_name} — {$vendor_name|escape:'html':'UTF-8'}{/if}
      <span class="panel-heading-action"><a class="btn btn-default btn-sm bxmp-export" href="{$export_kpis|escape:'html':'UTF-8'}"><i class="icon-download"></i> KPIs CSV</a></span>
    </div>
    <div class="bxmp-kpis">
    {foreach $kpis as $kpi}
      <div class="bxmp-kpi{if $kpi.main} bxmp-kpi-main{/if}" data-kpi="{$kpi.key|escape:'html':'UTF-8'}">
        <div class="bxmp-kpi-label">{$kpi.label|escape:'html':'UTF-8'}</div>
        <div class="bxmp-kpi-value">
          {if $kpi.value === null}–
          {elseif $kpi.type == 'money'}{$kpi.value|string_format:'%.2f'} €
          {elseif $kpi.type == 'pct'}{$kpi.value|string_format:'%.2f'} %
          {elseif $kpi.type == 'int'}{$kpi.value|intval}
          {else}{$kpi.value|escape:'html':'UTF-8'}{/if}
        </div>
        <div class="bxmp-kpi-sub">
          {if $kpi.delta !== null}<span class="{if $kpi.delta >= 0}bxmp-delta-up{else}bxmp-delta-down{/if}">{if $kpi.delta >= 0}▲{else}▼{/if} {$kpi.delta|string_format:'%.1f'} %</span> vs prev. ·{/if}
          {$kpi.sub|escape:'html':'UTF-8'}
        </div>
      </div>
    {/foreach}
    </div>
    <p class="text-muted small">Amounts tax excluded, cancelled vendor orders excluded. Conversion uses native PrestaShop visit tracking (ps_connections); cart abandonment = carts with products created in the period without an order. Offers per product is marketplace-wide.</p>
  </div>

  <div class="bxmp-two">
    <div class="panel">
      <div class="panel-heading"><i class="icon-bar-chart"></i> GMV trend (per {$granularity|escape:'html':'UTF-8'})
        <span class="panel-heading-action"><a class="btn btn-default btn-sm bxmp-export" href="{$export_trend|escape:'html':'UTF-8'}"><i class="icon-download"></i> CSV</a></span></div>
      {$chart nofilter}
    </div>
    <div class="panel">
      <div class="panel-heading"><i class="icon-pie-chart"></i> GMV by vendor &amp; vendor order status</div>
      {if $share_chart}{$share_chart nofilter}{else}<p class="text-muted">No sales in this period.</p>{/if}
      <table class="table table-condensed" style="margin-top:10px">
        <thead><tr><th>Status</th><th class="text-right">Vendor orders</th><th class="text-right">Amount</th></tr></thead>
        <tbody>{foreach $status as $st => $s}<tr><td>{$st|escape:'html':'UTF-8'}</td><td class="text-right">{$s.n|intval}</td><td class="text-right">{$s.amount|string_format:'%.2f'} €</td></tr>{/foreach}</tbody>
      </table>
      <p>{foreach $links as $label => $href}<a class="btn btn-default btn-sm" href="{$href|escape:'html':'UTF-8'}">{$label|escape:'html':'UTF-8'}</a> {/foreach}</p>
    </div>
  </div>
</div>
