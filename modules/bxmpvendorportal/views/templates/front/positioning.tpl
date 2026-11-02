{extends file='module:bxmpvendorportal/views/templates/front/_portal.tpl'}
{block name='portal_title'}Price positioning{/block}

{block name='portal_content'}
  <p class="bxmpv-note">Position of each of your SKUs against all other vendors selling the <b>identical</b> product / combination (effective price incl. live discounts, tax excl., in-stock active offers). The suggested price undercuts the best competitor by {$pos.step|string_format:'%.2f'}.</p>
  <div class="bxmpv-kpis bxmpv-kpis--small" data-testid="positioning-summary">
    <a class="bxmpv-kpi{if $pos.filter == ''} is-current{/if}" href="{$pos.base_url|escape:'html':'UTF-8'}"><span class="bxmpv-kpi__label">SKUs</span><strong class="bxmpv-kpi__value">{$pos.summary.skus|intval}</strong></a>
    <a class="bxmpv-kpi{if $pos.filter == 'winning'} is-current{/if}" href="{$pos.filter_urls.winning|escape:'html':'UTF-8'}"><span class="bxmpv-kpi__label">Buy box won</span><strong class="bxmpv-kpi__value is-up">{$pos.summary.buy_box|intval}</strong></a>
    <a class="bxmpv-kpi{if $pos.filter == 'losing'} is-current{/if}" href="{$pos.filter_urls.losing|escape:'html':'UTF-8'}"><span class="bxmpv-kpi__label">Not cheapest</span><strong class="bxmpv-kpi__value is-down">{$pos.summary.losing|intval}</strong></a>
    <a class="bxmpv-kpi{if $pos.filter == 'alone'} is-current{/if}" href="{$pos.filter_urls.alone|escape:'html':'UTF-8'}"><span class="bxmpv-kpi__label">Sole seller</span><strong class="bxmpv-kpi__value">{$pos.summary.alone|intval}</strong></a>
    <a class="bxmpv-kpi{if $pos.filter == 'ineligible'} is-current{/if}" href="{$pos.filter_urls.ineligible|escape:'html':'UTF-8'}"><span class="bxmpv-kpi__label">Not ranked</span><strong class="bxmpv-kpi__value">{$pos.summary.ineligible|intval}</strong></a>
  </div>

  <form class="bxmpv-filters" method="get" action="{$pos.base_url|escape:'html':'UTF-8'}">
    <input type="hidden" name="filter" value="{$pos.filter|escape:'html':'UTF-8'}">
    <label class="bxmpv-grow">Search <input type="search" name="q" class="form-control" value="{$pos.q|escape:'html':'UTF-8'}" placeholder="Name, EAN, SKU"></label>
    <button class="btn btn-secondary" type="submit">Filter</button>
  </form>

  <div class="bxmpv-table-wrap">
    <table class="bxmpv-table bxmpv-table--stack" data-testid="positioning-table">
      <thead><tr><th>Product</th><th class="num">Your price</th><th>Rank</th><th class="num">Best competitor</th><th class="num">Δ %</th><th>Buy box</th><th class="num">Suggested</th>{if $bxmpv.can.offers_edit}<th></th>{/if}</tr></thead>
      <tbody>
      {foreach $pos.rows as $o}
        {assign var=p value=$o.position}
        <tr data-offer="{$o.id_offer|intval}">
          <td data-label="Product"><span class="bxmpv-strong">{$o.product_name|escape:'html':'UTF-8'}</span>{if $o.combination}<br><small>{$o.combination|escape:'html':'UTF-8'}</small>{/if}<br><small class="bxmpv-muted"><code>{$o.master_ean13|escape:'html':'UTF-8'}</code> {$o.vendor_sku|escape:'html':'UTF-8'}</small></td>
          <td data-label="Your price" class="num">{if $p.own_price !== null}{$p.own_price|string_format:'%.2f'}{/if}{if $p.discount_active}<br><small class="bxmpv-muted">list {$o.price|string_format:'%.2f'}</small>{/if}</td>
          <td data-label="Rank">{if $p.reason}<span class="bxmpv-badge bxmpv-badge--muted">{if $p.reason == 'out_of_stock'}out of stock{else}{$p.reason|escape:'html':'UTF-8'}{/if}</span>{elseif $p.rank}#{$p.rank|intval} of {$p.sellers|intval}{/if}</td>
          <td data-label="Best competitor" class="num">{if $p.best_competitor_price !== null}{$p.best_competitor_price|string_format:'%.2f'}<br><small class="bxmpv-muted">{$p.best_competitor_name|escape:'html':'UTF-8'}</small>{else}<span class="bxmpv-muted">none</span>{/if}</td>
          <td data-label="Δ %" class="num">{if $p.delta_pct !== null}<b class="{if $p.delta_pct > 0}is-down{else}is-up{/if}">{if $p.delta_pct > 0}+{/if}{$p.delta_pct|string_format:'%.1f'}%</b>{else}—{/if}</td>
          <td data-label="Buy box">{if $p.buy_box}<span class="bxmpv-badge bxmpv-badge--win">★ You</span>{elseif $p.buy_box_vendor}<span class="bxmpv-muted">{$p.buy_box_vendor|escape:'html':'UTF-8'}</span>{else}—{/if}</td>
          <td data-label="Suggested" class="num" data-testid="suggested">
            {if $p.suggestion.action == 'lower'}<b class="is-down">{$p.suggestion.suggested|string_format:'%.2f'}</b><br><small class="bxmpv-muted">cut {$p.suggestion.delta|string_format:'%.2f'}</small>
            {elseif $p.suggestion.action == 'raise'}<b class="is-up">{$p.suggestion.suggested|string_format:'%.2f'}</b><br><small class="bxmpv-muted">room +{$p.suggestion.delta|string_format:'%.2f'}</small>
            {elseif $p.suggestion.action == 'keep'}<span class="bxmpv-muted">keep</span>
            {elseif $p.suggestion.action == 'floor'}<span class="bxmpv-muted">below floor</span>
            {else}—{/if}
          </td>
          {if $bxmpv.can.offers_edit}
            <td class="bxmpv-actions">
              {if $p.suggestion.suggested !== null && ($p.suggestion.action == 'lower' || $p.suggestion.action == 'raise')}
                <form method="post" action="{$pos.base_url|escape:'html':'UTF-8'}" data-confirm="Set the price to {$p.suggestion.suggested|string_format:'%.2f'}? Any running discount on this offer is removed.">
                  <input type="hidden" name="csrf_token" value="{$bxmpv.csrf|escape:'html':'UTF-8'}">
                  <input type="hidden" name="action" value="apply">
                  <input type="hidden" name="id_offer" value="{$o.id_offer|intval}">
                  <input type="hidden" name="price" value="{$p.suggestion.suggested|string_format:'%.2f'}">
                  <input type="hidden" name="filter" value="{$pos.filter|escape:'html':'UTF-8'}">
                  <button class="btn btn-secondary btn-sm" type="submit">Apply</button>
                </form>
              {/if}
            </td>
          {/if}
        </tr>
      {foreachelse}
        <tr><td colspan="8" class="bxmpv-empty">No SKUs in this view.</td></tr>
      {/foreach}
      </tbody>
    </table>
  </div>
{/block}
