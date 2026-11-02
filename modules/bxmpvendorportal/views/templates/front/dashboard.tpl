{extends file='module:bxmpvendorportal/views/templates/front/_portal.tpl'}
{block name='portal_title'}Dashboard{/block}

{block name='portal_content'}
  <div class="bxmpv-ops" data-testid="open-orders">
    <a class="bxmpv-ops__item" href="{$dash.status_urls.pending|escape:'html':'UTF-8'}"><b>{$dash.open_orders.pending|intval}</b><span>to accept</span></a>
    <a class="bxmpv-ops__item" href="{$dash.status_urls.accepted|escape:'html':'UTF-8'}"><b>{$dash.open_orders.accepted|intval}</b><span>to ship</span></a>
    <a class="bxmpv-ops__item" href="{$dash.status_urls.shipped|escape:'html':'UTF-8'}"><b>{$dash.open_orders.shipped|intval}</b><span>in transit</span></a>
  </div>

  {if $dash.analytics}
    {assign var=a value=$dash.analytics}
    <form class="bxmpv-filters" method="get" action="{$a.dashboard_url|escape:'html':'UTF-8'}" data-testid="period-form">
      <label>Period
        <select name="period" class="form-control" data-autosubmit data-toggle-custom>
          {foreach $a.presets as $k => $label}<option value="{$k|escape:'html':'UTF-8'}"{if $a.period.preset == $k} selected{/if}>{$label|escape:'html':'UTF-8'}</option>{/foreach}
        </select>
      </label>
      <label class="bxmpv-custom{if $a.period.preset != 'custom'} is-hidden{/if}">From <input type="date" name="from" class="form-control" value="{$a.period.from|escape:'html':'UTF-8'}"></label>
      <label class="bxmpv-custom{if $a.period.preset != 'custom'} is-hidden{/if}">To <input type="date" name="to" class="form-control" value="{$a.period.to|escape:'html':'UTF-8'}"></label>
      <label>Trend by
        <select name="granularity" class="form-control" data-autosubmit>
          {foreach ['day' => 'Day', 'week' => 'Week', 'month' => 'Month'] as $k => $label}<option value="{$k}"{if $a.period.granularity == $k} selected{/if}>{$label}</option>{/foreach}
        </select>
      </label>
      <button class="btn btn-primary" type="submit">Apply</button>
      <span class="bxmpv-muted">{$a.period.from|escape:'html':'UTF-8'} → {$a.period.to|escape:'html':'UTF-8'} · compared with {$a.period.prev_from|escape:'html':'UTF-8'} → {$a.period.prev_to|escape:'html':'UTF-8'}</span>
    </form>

    <div class="bxmpv-kpis" data-testid="kpis">
      {foreach $a.tiles as $t}
        <div class="bxmpv-kpi" data-kpi="{$t.key|escape:'html':'UTF-8'}">
          <span class="bxmpv-kpi__label">{$t.label|escape:'html':'UTF-8'}</span>
          <strong class="bxmpv-kpi__value">{$t.value|escape:'html':'UTF-8'}</strong>
          <span class="bxmpv-kpi__foot">
            {if $t.delta !== null}<b class="{if $t.delta >= 0}is-up{else}is-down{/if}">{if $t.delta >= 0}▲ +{else}▼ {/if}{$t.delta|string_format:'%.1f'}%</b>{else}<b class="is-flat">—</b>{/if}
            <span>{$t.hint|escape:'html':'UTF-8'}</span>
          </span>
        </div>
      {/foreach}
    </div>

    <div class="bxmpv-card">
      <div class="bxmpv-card__head">
        <h2>Sales trend</h2>
        <div class="bxmpv-legend"><span class="bxmpv-legend__bar"></span>GMV <span class="bxmpv-legend__line"></span>Net sales <span class="bxmpv-legend__dot"></span>Orders</div>
      </div>
      {if $a.trend_empty}<p class="bxmpv-empty">No sales in this period.</p>{/if}
      <div class="bxmpv-chart" id="bxmpv-trend" data-series="{$a.trend_json|escape:'html':'UTF-8'}" role="img" aria-label="Sales trend chart"></div>
    </div>

    <div class="bxmpv-grid2 bxmpv-grid2--dash">
      <div class="bxmpv-card">
        <div class="bxmpv-card__head"><h2>Best-selling products</h2></div>
        <div class="bxmpv-table-wrap">
          <table class="bxmpv-table" data-testid="best-sellers">
            <thead><tr><th>#</th><th>Product</th><th class="num">Units</th><th class="num">Revenue</th><th class="num">Avg price</th><th class="num">Orders</th></tr></thead>
            <tbody>
              {foreach $a.best as $i => $b}
                <tr>
                  <td>{$i+1}</td>
                  <td><span class="bxmpv-strong">{$b.product_name|escape:'html':'UTF-8'}</span><br><small class="bxmpv-muted">{$b.vendor_sku|escape:'html':'UTF-8'}</small></td>
                  <td class="num">{$b.qty|intval}</td>
                  <td class="num">{$b.revenue|string_format:'%.2f'}</td>
                  <td class="num">{$b.avg_price|string_format:'%.2f'}</td>
                  <td class="num">{$b.orders|intval}</td>
                </tr>
              {foreachelse}
                <tr><td colspan="6" class="bxmpv-empty">No sales in this period.</td></tr>
              {/foreach}
            </tbody>
          </table>
        </div>
      </div>
      <div class="bxmpv-card">
        <div class="bxmpv-card__head"><h2>Orders by status</h2></div>
        {assign var=stotal value=0}{foreach $a.status as $s}{assign var=stotal value=$stotal+$s.count}{/foreach}
        <ul class="bxmpv-status" data-testid="status-breakdown">
          {foreach $a.status as $st => $s}
            <li>
              <span class="bxmpv-pill bxmpv-pill--{$st|escape:'html':'UTF-8'}">{$st|escape:'html':'UTF-8'}</span>
              <span class="bxmpv-status__bar"><i style="width: {if $stotal}{($s.count*100/$stotal)|string_format:'%.1f'}{else}0{/if}%"></i></span>
              <b>{$s.count|intval}</b>
              <small class="bxmpv-muted">{$s.amount|string_format:'%.2f'}</small>
            </li>
          {/foreach}
        </ul>
      </div>
    </div>
  {else}
    <div class="bxmpv-card"><p>Your role gives access to orders and shipments. Financial analytics are reserved to managers and owners.</p>
      <a class="btn btn-primary" href="{$dash.orders_url|escape:'html':'UTF-8'}">Go to orders</a></div>
  {/if}
{/block}
