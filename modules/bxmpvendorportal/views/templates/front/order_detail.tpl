{extends file='module:bxmpvendorportal/views/templates/front/_portal.tpl'}
{block name='portal_title'}{assign var=o value=$ord.order}Order {$o.reference|escape:'html':'UTF-8'}{/block}
{block name='portal_actions'}<a class="btn btn-secondary" href="{$ord.list_url|escape:'html':'UTF-8'}"><i class="material-icons">arrow_back</i> All orders</a>{/block}

{block name='portal_content'}
  {assign var=o value=$ord.order}
  <div class="bxmpv-grid2">
    <div class="bxmpv-card" data-testid="order-summary">
      <div class="bxmpv-card__head"><h2>Sub-order #{$o.id_vendor_order|intval}</h2><span class="bxmpv-pill bxmpv-pill--{$o.status|escape:'html':'UTF-8'}" data-testid="order-status">{$o.status|escape:'html':'UTF-8'}</span></div>
      <dl class="bxmpv-dl">
        <dt>Created</dt><dd>{$o.date_add|escape:'html':'UTF-8'}</dd>
        <dt>Last update</dt><dd>{$o.date_upd|escape:'html':'UTF-8'}</dd>
        <dt>Products</dt><dd>{$o.total_products|string_format:'%.2f'}</dd>
        <dt>Shipping</dt><dd>{$o.total_shipping|string_format:'%.2f'}</dd>
        <dt>Total paid</dt><dd><b>{$o.total_paid|string_format:'%.2f'}</b></dd>
        <dt>Commission</dt><dd>{$o.commission_amount|string_format:'%.2f'}</dd>
        <dt>Your payout</dt><dd><b>{$o.vendor_payout|string_format:'%.2f'}</b></dd>
        {if $o.tracking_number}<dt>Carrier</dt><dd>{$o.carrier_name|escape:'html':'UTF-8'}</dd><dt>Tracking</dt><dd data-testid="tracking"><code>{$o.tracking_number|escape:'html':'UTF-8'}</code></dd>{/if}
        {if $o.payment_transfer_ref}<dt>Payout transfer</dt><dd><code>{$o.payment_transfer_ref|escape:'html':'UTF-8'}</code></dd>{/if}
      </dl>
    </div>
    <div class="bxmpv-card" data-testid="delivery">
      <div class="bxmpv-card__head"><h2>Delivery</h2></div>
      {if $ord.parent}
        <p><b>{$ord.parent.customer_name|escape:'html':'UTF-8'}</b><br>{$ord.parent.address_html nofilter}</p>
        {if $ord.parent.phone}<p><i class="material-icons bxmpv-ic">phone</i> {$ord.parent.phone|escape:'html':'UTF-8'}</p>{/if}
        <p class="bxmpv-muted">Marketplace order {$ord.parent.reference|escape:'html':'UTF-8'} of {$ord.parent.date_add|escape:'html':'UTF-8'}</p>
      {elseif $o.is_history}
        <p class="bxmpv-muted">Demo history record (HIST-): no customer delivery address.</p>
      {else}
        <p class="bxmpv-muted">Parent order not available.</p>
      {/if}
    </div>
  </div>

  <div class="bxmpv-card">
    <div class="bxmpv-card__head"><h2>Lines</h2></div>
    <div class="bxmpv-table-wrap">
      <table class="bxmpv-table bxmpv-table--stack" data-testid="order-lines">
        <thead><tr><th>Product</th><th>SKU</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Total</th><th class="num">Commission</th></tr></thead>
        <tbody>
        {foreach $o.lines as $l}
          <tr>
            <td data-label="Product">{$l.product_name|escape:'html':'UTF-8'}</td>
            <td data-label="SKU"><code>{$l.vendor_sku|escape:'html':'UTF-8'}</code></td>
            <td data-label="Qty" class="num">{$l.quantity|intval}</td>
            <td data-label="Unit price" class="num">{$l.unit_price|string_format:'%.2f'}</td>
            <td data-label="Total" class="num">{($l.unit_price*$l.quantity)|string_format:'%.2f'}</td>
            <td data-label="Commission" class="num">{$l.commission_amount|string_format:'%.2f'}</td>
          </tr>
        {/foreach}
        </tbody>
      </table>
    </div>
  </div>

  {if $ord.can_manage && $o.actions|count}
    <div class="bxmpv-card" data-testid="order-actions">
      <div class="bxmpv-card__head"><h2>Fulfilment</h2></div>
      <div class="bxmpv-flow">
        {if in_array('accept', $o.actions)}
          <form method="post" action="{$ord.list_url|escape:'html':'UTF-8'}">
            <input type="hidden" name="csrf_token" value="{$bxmpv.csrf|escape:'html':'UTF-8'}">
            <input type="hidden" name="action" value="accept"><input type="hidden" name="id_vendor_order" value="{$o.id_vendor_order|intval}">
            <button class="btn btn-primary" type="submit" data-testid="accept">Accept order</button>
          </form>
        {/if}
        {if in_array('ship', $o.actions)}
          <form method="post" action="{$ord.list_url|escape:'html':'UTF-8'}" class="bxmpv-inline-form" data-testid="ship-form">
            <input type="hidden" name="csrf_token" value="{$bxmpv.csrf|escape:'html':'UTF-8'}">
            <input type="hidden" name="action" value="ship"><input type="hidden" name="id_vendor_order" value="{$o.id_vendor_order|intval}">
            <input type="text" name="carrier_name" class="form-control" list="bxmpv-carriers" placeholder="Carrier" required maxlength="128" aria-label="Carrier">
            <datalist id="bxmpv-carriers">{foreach $ord.carriers as $c}<option value="{$c|escape:'html':'UTF-8'}">{/foreach}</datalist>
            <input type="text" name="tracking_number" class="form-control" placeholder="Tracking number" required minlength="3" maxlength="64" pattern="[A-Za-z0-9][A-Za-z0-9 ._/\-]{literal}{2,63}{/literal}" aria-label="Tracking number">
            <button class="btn btn-primary" type="submit" data-testid="ship">Mark as shipped</button>
          </form>
        {/if}
        {if in_array('deliver', $o.actions)}
          <form method="post" action="{$ord.list_url|escape:'html':'UTF-8'}">
            <input type="hidden" name="csrf_token" value="{$bxmpv.csrf|escape:'html':'UTF-8'}">
            <input type="hidden" name="action" value="deliver"><input type="hidden" name="id_vendor_order" value="{$o.id_vendor_order|intval}">
            <button class="btn btn-primary" type="submit" data-testid="deliver">Mark as delivered</button>
          </form>
        {/if}
        {if in_array('cancel', $o.actions)}
          <form method="post" action="{$ord.list_url|escape:'html':'UTF-8'}" data-confirm="Cancel this order? Reserved stock is given back to your offers.">
            <input type="hidden" name="csrf_token" value="{$bxmpv.csrf|escape:'html':'UTF-8'}">
            <input type="hidden" name="action" value="cancel"><input type="hidden" name="id_vendor_order" value="{$o.id_vendor_order|intval}">
            <button class="btn btn-danger" type="submit" data-testid="cancel">Cancel order</button>
          </form>
        {/if}
      </div>
    </div>
  {/if}
{/block}
