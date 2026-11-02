{extends file='module:bxmpvendorportal/views/templates/front/_portal.tpl'}
{block name='portal_title'}Offers &amp; stock{/block}
{block name='portal_actions'}
  {if $bxmpv.can.offers_edit}
    <a class="btn btn-primary" href="{$off.add_url|escape:'html':'UTF-8'}" data-testid="add-offer-btn"><i class="material-icons">add</i> Add offer</a>
    <a class="btn btn-secondary" href="{$off.bulk_url|escape:'html':'UTF-8'}" data-testid="bulk-stock-btn"><i class="material-icons">inventory</i> Bulk stock</a>
  {/if}
{/block}

{block name='portal_content'}
  {if !$bxmpv.can.offers_edit}
    <p class="bxmpv-note" data-testid="readonly-note">Read-only view: your role ({$bxmpv.role|escape:'html':'UTF-8'}) cannot change offers.</p>
  {/if}

  {if $off.add_mode && $bxmpv.can.offers_edit}
    <div class="bxmpv-card" id="bxmpv-add" data-testid="add-offer">
      <div class="bxmpv-card__head"><h2>Add an offer on an existing master product</h2></div>
      <form method="get" action="{$off.base_url|escape:'html':'UTF-8'}" class="bxmpv-filters">
        <label class="bxmpv-grow">EAN, product name or reference
          <input type="search" name="mq" class="form-control" value="{$off.mq|escape:'html':'UTF-8'}" placeholder="e.g. 8000000000011 or Mug" required minlength="2" autofocus>
        </label>
        <button class="btn btn-primary" type="submit">Search catalog</button>
      </form>
      {if $off.mq}
        <div class="bxmpv-table-wrap">
          <table class="bxmpv-table bxmpv-table--stack">
            <thead><tr><th>Master product</th><th>EAN</th><th class="num">Sellers</th><th class="num">Best price</th><th>Your offer</th></tr></thead>
            <tbody>
            {foreach $off.master_results as $m}
              <tr>
                <td data-label="Product"><span class="bxmpv-strong">{$m.product_name|escape:'html':'UTF-8'}</span>{if $m.combination}<br><small>{$m.combination|escape:'html':'UTF-8'}</small>{/if}<br><small class="bxmpv-muted">{$m.reference|escape:'html':'UTF-8'}</small></td>
                <td data-label="EAN"><code>{$m.ean13|escape:'html':'UTF-8'}</code></td>
                <td data-label="Sellers" class="num">{$m.sellers|intval}</td>
                <td data-label="Best price" class="num">{if $m.best_price !== null}{$m.best_price|string_format:'%.2f'}{else}—{/if}</td>
                <td data-label="Your offer">
                  {if $m.own_offer_id}
                    <span class="bxmpv-badge bxmpv-badge--muted">Already offered</span>
                  {else}
                    <form method="post" action="{$off.base_url|escape:'html':'UTF-8'}" class="bxmpv-inline-form">
                      <input type="hidden" name="csrf_token" value="{$bxmpv.csrf|escape:'html':'UTF-8'}">
                      <input type="hidden" name="action" value="add">
                      <input type="hidden" name="mq" value="{$off.mq|escape:'html':'UTF-8'}">
                      <input type="hidden" name="id_product" value="{$m.id_product|intval}">
                      <input type="hidden" name="id_product_attribute" value="{$m.id_product_attribute|intval}">
                      <input type="text" name="vendor_sku" class="form-control" placeholder="Your SKU" maxlength="64" aria-label="Vendor SKU">
                      <input type="number" name="price" class="form-control" step="0.01" min="0.01" placeholder="Price excl. tax" required aria-label="Price">
                      <input type="number" name="quantity" class="form-control" step="1" min="0" value="0" required aria-label="Quantity">
                      <button class="btn btn-primary btn-sm" type="submit">Add</button>
                    </form>
                  {/if}
                </td>
              </tr>
            {foreachelse}
              <tr><td colspan="5" class="bxmpv-empty">No active master product matches “{$off.mq|escape:'html':'UTF-8'}”. Vendors can only sell products of the master catalog; new products are created by the marketplace (CSV import / matching queue).</td></tr>
            {/foreach}
            </tbody>
          </table>
        </div>
      {/if}
    </div>
  {/if}

  {if $off.bulk_mode && $bxmpv.can.offers_edit}
    <div class="bxmpv-card" data-testid="bulk-stock">
      <div class="bxmpv-card__head"><h2>Bulk stock update</h2></div>
      <form method="post" action="{$off.base_url|escape:'html':'UTF-8'}">
        <input type="hidden" name="csrf_token" value="{$bxmpv.csrf|escape:'html':'UTF-8'}">
        <input type="hidden" name="action" value="bulk_stock">
        <label for="bxmpv-bulk">One line per offer: <code>vendor SKU or EAN;quantity</code></label>
        <textarea id="bxmpv-bulk" name="bulk" rows="6" class="form-control bxmpv-mono" placeholder="VENDOR-A-P1;40&#10;8000000000028;12"></textarea>
        <button class="btn btn-primary" type="submit">Update stock</button>
      </form>
    </div>
  {/if}

  <form class="bxmpv-filters" method="get" action="{$off.base_url|escape:'html':'UTF-8'}" data-testid="offer-filters">
    <label class="bxmpv-grow">Search <input type="search" name="q" class="form-control" value="{$off.filters.q|escape:'html':'UTF-8'}" placeholder="Name, EAN, SKU, reference"></label>
    <label>Status
      <select name="status" class="form-control" data-autosubmit>
        {foreach $off.statuses as $k => $label}<option value="{$k|escape:'html':'UTF-8'}"{if $off.filters.status == $k} selected{/if}>{$label|escape:'html':'UTF-8'}</option>{/foreach}
      </select>
    </label>
    <button class="btn btn-secondary" type="submit">Filter</button>
  </form>

  <div class="bxmpv-table-wrap">
    <table class="bxmpv-table bxmpv-table--stack" data-testid="offers-table">
      <thead>
        <tr><th>Product</th><th>EAN / SKU</th><th class="num">Price</th><th class="num">Discount</th><th class="num">Stock</th><th>Status</th><th>Competitive position</th>{if $bxmpv.can.offers_edit}<th></th>{/if}</tr>
      </thead>
      <tbody>
      {foreach $off.rows as $o}
        <tr data-offer="{$o.id_offer|intval}" class="{if !$o.active}is-inactive{/if}">
          <td data-label="Product"><span class="bxmpv-strong">{$o.product_name|default:'(deleted product)'|escape:'html':'UTF-8'}</span>{if $o.combination}<br><small>{$o.combination|escape:'html':'UTF-8'}</small>{/if}</td>
          <td data-label="EAN / SKU"><code>{$o.master_ean13|escape:'html':'UTF-8'}</code><br><small class="bxmpv-muted">{$o.vendor_sku|escape:'html':'UTF-8'}</small></td>
          <td data-label="Price" class="num" data-testid="price">{$o.price|string_format:'%.2f'}</td>
          <td data-label="Discount" class="num">{if $o.discount_price}{$o.discount_price|string_format:'%.2f'}{if $o.position && $o.position.discount_active} <span class="bxmpv-badge bxmpv-badge--win">live</span>{/if}<br><small class="bxmpv-muted">{$o.discount_from|default:'…'|truncate:10:''|escape:'html':'UTF-8'} → {$o.discount_to|default:'…'|truncate:10:''|escape:'html':'UTF-8'}</small>{else}—{/if}</td>
          <td data-label="Stock" class="num{if $o.quantity <= 0} is-down{/if}" data-testid="qty">{$o.quantity|intval}</td>
          <td data-label="Status">{if $o.active}<span class="bxmpv-pill bxmpv-pill--delivered">active</span>{else}<span class="bxmpv-pill bxmpv-pill--cancelled">inactive</span>{/if}</td>
          <td data-label="Position">{include file='module:bxmpvendorportal/views/templates/front/_partials/position.tpl' p=$o.position}</td>
          {if $bxmpv.can.offers_edit}
            <td class="bxmpv-actions"><button type="button" class="btn btn-secondary btn-sm" data-toggle-row="edit-{$o.id_offer|intval}" data-testid="edit-offer">Edit</button></td>
          {/if}
        </tr>
        {if $bxmpv.can.offers_edit}
        <tr class="bxmpv-editrow{if $off.edit_id != $o.id_offer} is-hidden{/if}" id="edit-{$o.id_offer|intval}">
          <td colspan="8">
            <form method="post" action="{$off.base_url|escape:'html':'UTF-8'}" class="bxmpv-edit-form" data-testid="offer-form-{$o.id_offer|intval}">
              <input type="hidden" name="csrf_token" value="{$bxmpv.csrf|escape:'html':'UTF-8'}">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id_offer" value="{$o.id_offer|intval}">
              {foreach $off.list_params as $k => $v}<input type="hidden" name="{$k|escape:'html':'UTF-8'}" value="{$v|escape:'html':'UTF-8'}">{/foreach}
              <label>Price (excl. tax)<input type="number" name="price" class="form-control" step="0.01" min="0.01" value="{$o.price|string_format:'%.2f'}" required></label>
              <label>Discount price<input type="number" name="discount_price" class="form-control" step="0.01" min="0" value="{if $o.discount_price}{$o.discount_price|string_format:'%.2f'}{/if}"></label>
              <label>Discount from<input type="datetime-local" name="discount_from" class="form-control" value="{$o.discount_from_input|escape:'html':'UTF-8'}"></label>
              <label>Discount to<input type="datetime-local" name="discount_to" class="form-control" value="{$o.discount_to_input|escape:'html':'UTF-8'}"></label>
              <label>Stock<input type="number" name="quantity" class="form-control" step="1" min="0" value="{$o.quantity|intval}" required></label>
              <label class="bxmpv-check"><input type="checkbox" name="active" value="1"{if $o.active} checked{/if}> Active</label>
              {if $o.position && $o.position.suggestion.suggested !== null && $o.position.suggestion.action != 'keep'}
                <button type="button" class="btn btn-link btn-sm" data-fill-price="{$o.position.suggestion.suggested|string_format:'%.2f'}">Use suggested {$o.position.suggestion.suggested|string_format:'%.2f'}</button>
              {/if}
              <span class="bxmpv-edit-form__btns">
                <button class="btn btn-primary btn-sm" type="submit">Save</button>
                <button class="btn btn-secondary btn-sm" type="button" data-toggle-row="edit-{$o.id_offer|intval}">Cancel</button>
              </span>
            </form>
          </td>
        </tr>
        {/if}
      {foreachelse}
        <tr><td colspan="8" class="bxmpv-empty">No offers found.</td></tr>
      {/foreach}
      </tbody>
    </table>
  </div>
  {include file='module:bxmpvendorportal/views/templates/front/_partials/pagination.tpl' pg=$off.pagination}
{/block}
