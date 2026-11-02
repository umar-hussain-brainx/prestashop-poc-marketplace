{extends file='module:bxmpvendorportal/views/templates/front/_portal.tpl'}
{block name='portal_title'}Company &amp; shipping{/block}

{block name='portal_content'}
  {assign var=v value=$prof.vendor}
  <div class="bxmpv-grid2">
    <div class="bxmpv-card" data-testid="company-form">
      <div class="bxmpv-card__head"><h2>Company information</h2></div>
      <form method="post" action="{$prof.base_url|escape:'html':'UTF-8'}" class="bxmpv-form">
        <input type="hidden" name="csrf_token" value="{$bxmpv.csrf|escape:'html':'UTF-8'}">
        <input type="hidden" name="action" value="save_profile">
        <label>Shop name <input type="text" class="form-control" value="{$v.name|escape:'html':'UTF-8'}" disabled><small class="bxmpv-muted">Managed by the marketplace ({$v.code|escape:'html':'UTF-8'})</small></label>
        <label>Company <input type="text" name="company" class="form-control" value="{$v.company|escape:'html':'UTF-8'}" required maxlength="255"></label>
        <label>VAT number <input type="text" name="vat_number" class="form-control" value="{$v.vat_number|escape:'html':'UTF-8'}" maxlength="32" placeholder="IT01234567890"></label>
        <label>Contact email <input type="email" name="email" class="form-control" value="{$v.email|escape:'html':'UTF-8'}" required maxlength="255"></label>
        <label>Payout account <input type="text" name="payout_account" class="form-control" value="{$v.payout_account|escape:'html':'UTF-8'}" maxlength="255" placeholder="Mangopay wallet / IBAN reference"></label>
        <button class="btn btn-primary" type="submit">Save company information</button>
      </form>
    </div>
    <div class="bxmpv-card" data-testid="commission">
      <div class="bxmpv-card__head"><h2>Commission terms</h2><span class="bxmpv-badge bxmpv-badge--muted">read-only</span></div>
      <dl class="bxmpv-dl">
        <dt>Commission rate</dt><dd><b>{$v.commission_rate|escape:'html':'UTF-8'} %</b> of the line amount</dd>
        <dt>Fixed fee</dt><dd>{$v.commission_fixed|escape:'html':'UTF-8'} per order line</dd>
        <dt>On shipping</dt><dd>{if $v.commission_on_shipping}yes{else}no{/if}</dd>
      </dl>
      <p class="bxmpv-muted">Commission terms are negotiated with the marketplace operator. Contact them to request a change.</p>
    </div>
  </div>

  <div class="bxmpv-card" data-testid="shipping-rules">
    <div class="bxmpv-card__head"><h2>Shipping rules</h2><a class="btn btn-primary btn-sm" href="{$link->getModuleLink('bxmpvendorportal', 'profile', ['rule' => 'new'])|escape:'html':'UTF-8'}#rule-form" data-testid="new-rule"><i class="material-icons">add</i> New rule</a></div>
    <p class="bxmpv-muted">The rule with the highest priority wins, then the most specific (postcode &gt; country &gt; all). No rule at all = free delivery everywhere; rules but none matching = no delivery. An exclusion rule blocks delivery to the matched area.</p>
    <div class="bxmpv-table-wrap">
      <table class="bxmpv-table bxmpv-table--stack">
        <thead><tr><th>Country</th><th>Postcodes</th><th>Type</th><th class="num">Base cost</th><th class="num">Reduced</th><th class="num">Free from</th><th class="num">Priority</th><th></th></tr></thead>
        <tbody>
        {foreach $prof.rules as $r}
          <tr data-rule="{$r.id_shipping_rule|intval}">
            <td data-label="Country">{$r.country_name|escape:'html':'UTF-8'}</td>
            <td data-label="Postcodes">{if $r.zipcode_pattern}<code>{$r.zipcode_pattern|escape:'html':'UTF-8'}</code>{else}all{/if}</td>
            <td data-label="Type">{if $r.excluded}<span class="bxmpv-pill bxmpv-pill--cancelled">excluded</span>{else}<span class="bxmpv-pill bxmpv-pill--delivered">delivers</span>{/if}</td>
            <td data-label="Base cost" class="num">{if !$r.excluded}{$r.base_cost|string_format:'%.2f'}{else}—{/if}</td>
            <td data-label="Reduced" class="num">{if $r.reduced_cost !== null && !$r.excluded}{$r.reduced_cost|string_format:'%.2f'} ≥ {$r.reduced_threshold|string_format:'%.2f'}{else}—{/if}</td>
            <td data-label="Free from" class="num">{if $r.free_threshold !== null && !$r.excluded}{$r.free_threshold|string_format:'%.2f'}{else}—{/if}</td>
            <td data-label="Priority" class="num">{$r.priority|intval}</td>
            <td class="bxmpv-actions">
              <a class="btn btn-secondary btn-sm" href="{$link->getModuleLink('bxmpvendorportal', 'profile', ['rule' => $r.id_shipping_rule])|escape:'html':'UTF-8'}#rule-form">Edit</a>
              <form method="post" action="{$prof.base_url|escape:'html':'UTF-8'}" data-confirm="Delete this shipping rule?">
                <input type="hidden" name="csrf_token" value="{$bxmpv.csrf|escape:'html':'UTF-8'}">
                <input type="hidden" name="action" value="delete_rule"><input type="hidden" name="id_shipping_rule" value="{$r.id_shipping_rule|intval}">
                <button class="btn btn-link btn-sm bxmpv-danger" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        {foreachelse}
          <tr><td colspan="8" class="bxmpv-empty">No rule: you currently ship for free everywhere.</td></tr>
        {/foreach}
        </tbody>
      </table>
    </div>

    {if $prof.form}
      {assign var=f value=$prof.form}
      <form method="post" action="{$prof.base_url|escape:'html':'UTF-8'}" class="bxmpv-form bxmpv-form--grid" id="rule-form" data-testid="rule-form">
        <h3>{if $f.id_shipping_rule}Edit rule #{$f.id_shipping_rule|intval}{else}New rule{/if}</h3>
        <input type="hidden" name="csrf_token" value="{$bxmpv.csrf|escape:'html':'UTF-8'}">
        <input type="hidden" name="action" value="save_rule">
        <input type="hidden" name="id_shipping_rule" value="{$f.id_shipping_rule|intval}">
        <label>Country
          <select name="id_country" class="form-control">
            <option value="0">All countries</option>
            {foreach $prof.countries as $id => $name}<option value="{$id|intval}"{if $f.id_country == $id} selected{/if}>{$name|escape:'html':'UTF-8'}</option>{/foreach}
          </select>
        </label>
        <label>Postcodes <input type="text" name="zipcode_pattern" class="form-control" value="{$f.zipcode_pattern|escape:'html':'UTF-8'}" placeholder="empty = all · 20,21 · 00100-00199" maxlength="255"></label>
        <label class="bxmpv-check"><input type="checkbox" name="excluded" value="1"{if $f.excluded} checked{/if} data-toggle-excluded> Exclusion (no delivery here)</label>
        <label class="bxmpv-costs">Base cost <input type="number" name="base_cost" class="form-control" step="0.01" min="0" value="{$f.base_cost|escape:'html':'UTF-8'}"></label>
        <label class="bxmpv-costs">Reduced cost <input type="number" name="reduced_cost" class="form-control" step="0.01" min="0" value="{$f.reduced_cost|escape:'html':'UTF-8'}"></label>
        <label class="bxmpv-costs">… from subtotal <input type="number" name="reduced_threshold" class="form-control" step="0.01" min="0" value="{$f.reduced_threshold|escape:'html':'UTF-8'}"></label>
        <label class="bxmpv-costs">Free shipping from <input type="number" name="free_threshold" class="form-control" step="0.01" min="0" value="{$f.free_threshold|escape:'html':'UTF-8'}"></label>
        <label>Priority <input type="number" name="priority" class="form-control" step="1" value="{$f.priority|intval}"></label>
        <span class="bxmpv-form__btns">
          <button class="btn btn-primary" type="submit">Save rule</button>
          <a class="btn btn-secondary" href="{$prof.base_url|escape:'html':'UTF-8'}">Cancel</a>
        </span>
      </form>
    {/if}
  </div>

  <div class="bxmpv-card" data-testid="shipping-simulator">
    <div class="bxmpv-card__head"><h2>Shipping simulator</h2></div>
    <form method="get" action="{$prof.base_url|escape:'html':'UTF-8'}" class="bxmpv-filters">
      <label>Country
        <select name="sim_country" class="form-control">
          {foreach $prof.countries as $id => $name}<option value="{$id|intval}"{if ($prof.sim && $prof.sim.country == $id) || (!$prof.sim && $prof.default_country == $id)} selected{/if}>{$name|escape:'html':'UTF-8'}</option>{/foreach}
        </select>
      </label>
      <label>Postcode <input type="text" name="sim_zip" class="form-control" value="{if $prof.sim}{$prof.sim.zip|escape:'html':'UTF-8'}{else}20121{/if}" maxlength="16"></label>
      <label>Subtotal <input type="number" name="sim_subtotal" class="form-control" step="0.01" min="0" value="{if $prof.sim}{$prof.sim.subtotal|string_format:'%.2f'}{else}25.00{/if}"></label>
      <button class="btn btn-secondary" type="submit">Simulate</button>
      {if $prof.sim}
        <span class="bxmpv-sim" data-testid="sim-result">{if $prof.sim.deliverable}Shipping: <b>{$prof.sim.cost|escape:'html':'UTF-8'}</b>{else}<b class="is-down">No delivery to this destination</b>{/if}</span>
      {/if}
    </form>
  </div>
{/block}
