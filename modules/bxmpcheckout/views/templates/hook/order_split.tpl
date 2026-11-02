{* BrainX marketplace — vendor split of an order (displayOrderConfirmation / displayOrderDetail) *}
<section class="bxmpco-card bxmpco-order-split" id="bxmpco-order-split">
  <h3>{l s='Your order is shipped by %d vendors' sprintf=[$bxmpco_vendor_orders|count] d='Modules.Bxmpcheckout.Shop'}</h3>
  <table class="bxmpco-table">
    <thead>
      <tr>
        <th>{l s='Vendor order' d='Modules.Bxmpcheckout.Shop'}</th>
        <th>{l s='Items' d='Modules.Bxmpcheckout.Shop'}</th>
        <th class="num">{l s='Items total' d='Modules.Bxmpcheckout.Shop'}</th>
        <th class="num">{l s='Shipping' d='Modules.Bxmpcheckout.Shop'}</th>
        <th class="num">{l s='Total' d='Modules.Bxmpcheckout.Shop'}</th>
        <th>{l s='Status / tracking' d='Modules.Bxmpcheckout.Shop'}</th>
      </tr>
    </thead>
    <tbody>
    {foreach $bxmpco_vendor_orders as $vo}
      <tr data-vendor-order="{$vo.reference}">
        <td data-label="{l s='Vendor order' d='Modules.Bxmpcheckout.Shop'}"><strong>{$vo.vendor_name}</strong><br><span class="bxmpco-muted">{$vo.reference}</span></td>
        <td data-label="{l s='Items' d='Modules.Bxmpcheckout.Shop'}">{foreach $vo.lines as $l}{$l.quantity} × {$l.product_name}<br>{/foreach}</td>
        <td class="num" data-label="{l s='Items total' d='Modules.Bxmpcheckout.Shop'}">{$vo.total_products_fmt}</td>
        <td class="num" data-label="{l s='Shipping' d='Modules.Bxmpcheckout.Shop'}">{$vo.shipping_label}</td>
        <td class="num" data-label="{l s='Total' d='Modules.Bxmpcheckout.Shop'}"><strong>{$vo.total_paid_fmt}</strong></td>
        <td data-label="{l s='Status / tracking' d='Modules.Bxmpcheckout.Shop'}"><span class="bxmpco-status">{$vo.status}</span>{if $vo.tracking_number}<br><span class="bxmpco-muted">{$vo.carrier_name} {$vo.tracking_number}</span>{/if}</td>
      </tr>
    {/foreach}
    </tbody>
  </table>
  <p class="bxmpco-muted">{l s='One payment, one order: each vendor ships its part independently and you can follow every parcel here and in your order history.' d='Modules.Bxmpcheckout.Shop'}</p>
</section>
