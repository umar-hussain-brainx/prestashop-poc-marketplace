{* BrainX marketplace — vendor offers comparison (displayProductAdditionalInfo) *}
<section class="bxmpco-card bxmpco-offers" id="bxmpco-offers">
  <h3>{l s='Compare vendor offers' d='Modules.Bxmpcheckout.Shop'}</h3>
  {if $bxmpco_offers|count == 0}
    <p class="bxmpco-muted">{l s='This product is currently not offered by any vendor.' d='Modules.Bxmpcheckout.Shop'}</p>
  {else}
    <table class="bxmpco-table">
      <thead>
        <tr>
          <th>{l s='Vendor' d='Modules.Bxmpcheckout.Shop'}</th>
          <th class="num">{l s='Price' d='Modules.Bxmpcheckout.Shop'}</th>
          <th>{l s='Stock' d='Modules.Bxmpcheckout.Shop'}</th>
          <th>{l s='Shipping' d='Modules.Bxmpcheckout.Shop'}</th>
          <th class="num">{l s='1 pc delivered' d='Modules.Bxmpcheckout.Shop'}</th>
        </tr>
      </thead>
      <tbody>
      {foreach $bxmpco_offers as $o}
        <tr class="{if $o.best}is-best{/if}{if $o.reason} is-ineligible{/if}">
          <td data-label="{l s='Vendor' d='Modules.Bxmpcheckout.Shop'}">
            <strong>{$o.vendor}</strong>
            {if $o.best}<span class="bxmpco-badge bxmpco-badge--best">{l s='Best total' d='Modules.Bxmpcheckout.Shop'}</span>{/if}
            {if $o.condition != 'new'}<span class="bxmpco-badge bxmpco-badge--off">{$o.condition}</span>{/if}
          </td>
          <td class="num" data-label="{l s='Price' d='Modules.Bxmpcheckout.Shop'}">
            {if $o.on_discount}<span class="bxmpco-strike">{$o.price_fmt}</span>{/if}<strong>{$o.effective_fmt}</strong>
            {if $o.on_discount}<br><span class="bxmpco-badge bxmpco-badge--sale">{l s='Sale' d='Modules.Bxmpcheckout.Shop'}{if $o.discount_to} {l s='until' d='Modules.Bxmpcheckout.Shop'} {$o.discount_to}{/if}</span>{/if}
          </td>
          <td data-label="{l s='Stock' d='Modules.Bxmpcheckout.Shop'}">
            {if $o.stock > 0}{$o.stock} {l s='in stock' d='Modules.Bxmpcheckout.Shop'}{else}<span class="bxmpco-badge bxmpco-badge--off">{l s='Out of stock' d='Modules.Bxmpcheckout.Shop'}</span>{/if}
          </td>
          <td data-label="{l s='Shipping' d='Modules.Bxmpcheckout.Shop'}">
            <span class="bxmpco-muted">{$o.shipping_rule}</span>
          </td>
          <td class="num" data-label="{l s='1 pc delivered' d='Modules.Bxmpcheckout.Shop'}">
            {if $o.reason}<span class="bxmpco-muted">{$o.reason}</span>{else}{$o.landed_fmt}<br><span class="bxmpco-muted">{l s='ship.' d='Modules.Bxmpcheckout.Shop'} {$o.shipping_fmt}</span>{/if}
          </td>
        </tr>
      {/foreach}
      </tbody>
    </table>
    <p class="bxmpco-muted">{l s='Prices %s, shipping to %s. Your cart is automatically split across vendors for the lowest total including shipping — the same product is never substituted.' sprintf=[$bxmpco_tax_label, $bxmpco_destination] d='Modules.Bxmpcheckout.Shop'}</p>
  {/if}
</section>
