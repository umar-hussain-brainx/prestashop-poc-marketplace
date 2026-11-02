{* competitive position badge for one offer: $p = position array *}
{if $p}
<div class="bxmpv-pos" data-testid="position" data-rank="{if $p.rank}{$p.rank|intval}{/if}" data-buybox="{if $p.buy_box}1{else}0{/if}">
  {if $p.reason}
    <span class="bxmpv-badge bxmpv-badge--muted" title="Not ranked">{if $p.reason == 'out_of_stock'}Out of stock{elseif $p.reason == 'inactive'}Inactive{else}{$p.reason|escape:'html':'UTF-8'}{/if}</span>
  {elseif $p.buy_box}
    <span class="bxmpv-badge bxmpv-badge--win" title="Cheapest eligible offer: wins the buy box">★ Buy box</span>
  {else}
    <span class="bxmpv-badge bxmpv-badge--rank">#{$p.rank|intval} / {$p.sellers|intval}</span>
  {/if}
  {if $p.best_competitor_price !== null}
    <span class="bxmpv-pos__best">best rival {$p.best_competitor_price|string_format:'%.2f'}{if $p.delta_pct !== null} <b class="{if $p.delta_pct > 0}is-down{else}is-up{/if}">{if $p.delta_pct > 0}+{/if}{$p.delta_pct|string_format:'%.1f'}%</b>{/if}</span>
  {else}
    <span class="bxmpv-pos__best">no competitor</span>
  {/if}
</div>
{/if}
