{* BrainX marketplace — vendor sub-orders on the BO order page (displayAdminOrderMain) *}
<div class="card mt-2" id="bxmpcoVendorSplit">
  <div class="card-header">
    <h3 class="card-header-title">{l s='Marketplace vendor split' d='Modules.Bxmpcheckout.Admin'} ({$bxmpco_vendor_orders|count})</h3>
  </div>
  <div class="card-body">
    {if $bxmpco_updated}<div class="alert alert-success" role="alert"><p class="alert-text">{l s='Vendor sub-order updated.' d='Modules.Bxmpcheckout.Admin'}</p></div>{/if}
    {if $bxmpco_vendor_orders|count == 0}
      <p class="text-muted">{if $bxmpco_is_marketplace}{l s='No vendor sub-order recorded for this order (see Marketplace logs, channel "checkout").' d='Modules.Bxmpcheckout.Admin'}{else}{l s='Not a marketplace order.' d='Modules.Bxmpcheckout.Admin'}{/if}</p>
    {else}
    <table class="table">
      <thead>
        <tr>
          <th>{l s='Vendor order' d='Modules.Bxmpcheckout.Admin'}</th>
          <th>{l s='Lines' d='Modules.Bxmpcheckout.Admin'}</th>
          <th class="text-right">{l s='Products' d='Modules.Bxmpcheckout.Admin'}</th>
          <th class="text-right">{l s='Shipping' d='Modules.Bxmpcheckout.Admin'}</th>
          <th class="text-right">{l s='Paid' d='Modules.Bxmpcheckout.Admin'}</th>
          <th class="text-right">{l s='Commission' d='Modules.Bxmpcheckout.Admin'}</th>
          <th class="text-right">{l s='Payout' d='Modules.Bxmpcheckout.Admin'}</th>
          <th>{l s='Payment transfer' d='Modules.Bxmpcheckout.Admin'}</th>
          <th style="min-width:330px">{l s='Fulfilment (status / carrier / tracking)' d='Modules.Bxmpcheckout.Admin'}</th>
        </tr>
      </thead>
      <tbody>
      {foreach $bxmpco_vendor_orders as $vo}
        <tr>
          <td><strong>{$vo.vendor_name}</strong><br><small class="text-muted">{$vo.reference}</small></td>
          <td>{foreach $vo.lines as $l}{$l.quantity} × {$l.product_name} @ {$l.unit_price_fmt}{if $l.vendor_sku} <small class="text-muted">[{$l.vendor_sku}]</small>{/if}<br>{/foreach}</td>
          <td class="text-right">{$vo.total_products_fmt}</td>
          <td class="text-right">{$vo.shipping_label}</td>
          <td class="text-right"><strong>{$vo.total_paid_fmt}</strong></td>
          <td class="text-right">{$vo.commission_amount_fmt}<br><small class="text-muted">{$vo.commission_rate}%</small></td>
          <td class="text-right">{$vo.vendor_payout_fmt}</td>
          <td><small>{$vo.payment_transfer_ref|default:'-'}</small></td>
          <td>
            <form method="post" action="{$bxmpco_action|escape:'html':'UTF-8'}" class="form-inline">
              <input type="hidden" name="id_order" value="{$bxmpco_id_order|intval}">
              <input type="hidden" name="id_vendor_order" value="{$vo.id_vendor_order|intval}">
              <select name="status" class="form-control form-control-sm mr-1 mb-1">
                {foreach $bxmpco_statuses as $s}<option value="{$s}"{if $s == $vo.status} selected{/if}>{$s}</option>{/foreach}
              </select>
              <input type="text" name="carrier_name" class="form-control form-control-sm mr-1 mb-1" style="width:90px" placeholder="{l s='Carrier' d='Modules.Bxmpcheckout.Admin'}" value="{$vo.carrier_name|escape:'html':'UTF-8'}">
              <input type="text" name="tracking_number" class="form-control form-control-sm mr-1 mb-1" style="width:110px" placeholder="{l s='Tracking #' d='Modules.Bxmpcheckout.Admin'}" value="{$vo.tracking_number|escape:'html':'UTF-8'}">
              <button type="submit" name="submitBxmpVendorOrder" value="1" class="btn btn-sm btn-primary mb-1">{l s='Save' d='Admin.Actions'}</button>
            </form>
          </td>
        </tr>
      {/foreach}
      </tbody>
      <tfoot>
        <tr><th colspan="4" class="text-right">{l s='Totals' d='Modules.Bxmpcheckout.Admin'}</th><th class="text-right">{$bxmpco_totals.paid}</th><th class="text-right">{$bxmpco_totals.commission}</th><th class="text-right">{$bxmpco_totals.payout}</th><th colspan="2"></th></tr>
      </tfoot>
    </table>
    {/if}
  </div>
</div>
