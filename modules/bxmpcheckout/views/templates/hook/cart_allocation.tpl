{* BrainX marketplace — optimized multi-vendor allocation (displayShoppingCartFooter / displayBeforeCarrier) *}
{if $bxmpco_state == 'unavailable'}
  <div class="bxmpco-card bxmpco-alert" id="bxmpco-blocked">{$bxmpco_block_message}</div>
{elseif $bxmpco_alloc}
<section class="bxmpco-card bxmpco-allocation bxmpco-allocation--{$bxmpco_variant}" id="bxmpco-allocation">
  <h3>{l s='Optimized allocation' d='Modules.Bxmpcheckout.Shop'}
    <span class="bxmpco-muted">— {$bxmpco_alloc.vendor_count} {if $bxmpco_alloc.vendor_count > 1}{l s='vendors' d='Modules.Bxmpcheckout.Shop'}{else}{l s='vendor' d='Modules.Bxmpcheckout.Shop'}{/if}, {l s='delivery to' d='Modules.Bxmpcheckout.Shop'} {$bxmpco_destination}</span>
  </h3>
  <div class="bxmpco-vendors">
    {foreach $bxmpco_alloc.vendors as $v}
      <div class="bxmpco-vendor" data-vendor="{$v.id_vendor}">
        <div class="bxmpco-vendor__head"><span>{$v.name}</span>
          {if $v.shipping <= 0}<span class="bxmpco-badge bxmpco-badge--free">{l s='Free shipping' d='Modules.Bxmpcheckout.Shop'}</span>{/if}
        </div>
        {if $bxmpco_variant == 'full'}
        <ul>
          {foreach $v.lines as $l}
            <li><span>{$l.quantity} × {$l.name}{if isset($bxmpco_alloc.split_lines["`$l.id_product`-`$l.id_product_attribute`"])} <span class="bxmpco-badge bxmpco-badge--off">{l s='split' d='Modules.Bxmpcheckout.Shop'}</span>{/if}</span><span class="num">{$l.total_fmt}</span></li>
          {/foreach}
        </ul>
        {/if}
        <div class="bxmpco-row"><span>{l s='Items' d='Modules.Bxmpcheckout.Shop'}</span><span>{$v.subtotal_fmt}</span></div>
        <div class="bxmpco-row"><span>{l s='Shipping' d='Modules.Bxmpcheckout.Shop'}</span><span>{$v.shipping_fmt}</span></div>
        <div class="bxmpco-row bxmpco-row--total"><span>{l s='Sub-order' d='Modules.Bxmpcheckout.Shop'}</span><span>{$v.total_fmt}</span></div>
        {if $v.hint}
          <div class="bxmpco-hint">{$v.hint.text}
            <div class="bxmpco-progress" title="{$v.hint.progress}%"><span style="width:{$v.hint.progress}%"></span></div>
          </div>
        {elseif $v.rule_summary && $bxmpco_variant == 'full'}
          <div class="bxmpco-muted">{$v.rule_summary}</div>
        {/if}
      </div>
    {/foreach}
  </div>
  <div class="bxmpco-summary">
    <span>{l s='Items' d='Modules.Bxmpcheckout.Shop'} <strong data-bxmpco="items">{$bxmpco_alloc.items_total_fmt}</strong></span>
    <span>{l s='Shipping' d='Modules.Bxmpcheckout.Shop'} <strong data-bxmpco="shipping">{$bxmpco_alloc.shipping_total_fmt}</strong></span>
    <span>{l s='Total' d='Modules.Bxmpcheckout.Shop'} <strong data-bxmpco="total">{$bxmpco_alloc.total_fmt}</strong></span>
    {foreach $bxmpco_alloc.savings as $s}
      <span class="bxmpco-savings">{l s='You save' d='Modules.Bxmpcheckout.Shop'} {$s.amount_fmt} {$s.label}</span>
    {/foreach}
  </div>
  {if $bxmpco_has_tax}<p class="bxmpco-muted">{l s='Allocation amounts are tax excluded; taxes are added on the order.' d='Modules.Bxmpcheckout.Shop'}</p>{/if}
  {if $bxmpco_variant == 'full' && $bxmpco_show_alternatives && $bxmpco_alloc.alternatives|count > 0}
    <details class="bxmpco-alt">
      <summary>{l s='Alternatives considered' d='Modules.Bxmpcheckout.Shop'} ({if $bxmpco_alloc.proven_optimal}{l s='optimum proven' d='Modules.Bxmpcheckout.Shop'}{else}{$bxmpco_alloc.mode}{/if})</summary>
      <table class="bxmpco-table">
        <thead><tr><th>{l s='Option' d='Modules.Bxmpcheckout.Shop'}</th><th class="num">{l s='Total' d='Modules.Bxmpcheckout.Shop'}</th><th class="num">{l s='vs. selected' d='Modules.Bxmpcheckout.Shop'}</th></tr></thead>
        <tbody>
        {foreach $bxmpco_alloc.alternatives as $a}
          <tr class="{if $a.selected}is-selected{/if}{if !$a.feasible} is-ineligible{/if}">
            <td data-label="{l s='Option' d='Modules.Bxmpcheckout.Shop'}">{$a.label}{if $a.note} <span class="bxmpco-muted">({$a.note})</span>{/if}</td>
            <td class="num" data-label="{l s='Total' d='Modules.Bxmpcheckout.Shop'}">{$a.total_fmt}</td>
            <td class="num" data-label="{l s='vs. selected' d='Modules.Bxmpcheckout.Shop'}">{$a.extra_fmt}</td>
          </tr>
        {/foreach}
        </tbody>
      </table>
    </details>
  {/if}
</section>
{/if}
