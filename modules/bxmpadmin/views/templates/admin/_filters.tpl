<form method="get" action="{$action|escape:'html':'UTF-8'}" class="bxmp-filters form-inline panel">
  <input type="hidden" name="controller" value="{$controller|escape:'html':'UTF-8'}">
  <input type="hidden" name="token" value="{$token|escape:'html':'UTF-8'}">
  {foreach $extra.hidden|default:[] as $hk => $hv}<input type="hidden" name="{$hk|escape:'html':'UTF-8'}" value="{$hv|escape:'html':'UTF-8'}">{/foreach}
  <div class="form-group">
    <label for="bxmp-period">Period</label>
    <select name="period" id="bxmp-period" class="form-control" onchange="document.getElementById('bxmp-custom').style.display = this.value === 'custom' ? 'inline-block' : 'none'">
      {foreach $presets as $k => $label}<option value="{$k|escape:'html':'UTF-8'}"{if $k == $preset} selected{/if}>{$label|escape:'html':'UTF-8'}</option>{/foreach}
    </select>
  </div>
  <div class="form-group" id="bxmp-custom" style="display:{if $preset == 'custom'}inline-block{else}none{/if}">
    <input type="date" name="date_from" value="{$date_from|escape:'html':'UTF-8'}" class="form-control"> →
    <input type="date" name="date_to" value="{$date_to|escape:'html':'UTF-8'}" class="form-control">
  </div>
  {if $with_vendor}
  <div class="form-group">
    <label for="bxmp-vendor">Vendor</label>
    <select name="id_vendor" id="bxmp-vendor" class="form-control">
      <option value="0">All vendors</option>
      {foreach $vendors as $vid => $vname}<option value="{$vid|intval}"{if $vid == $id_vendor} selected{/if}>{$vname|escape:'html':'UTF-8'}</option>{/foreach}
    </select>
  </div>
  {/if}
  {foreach $extra.selects|default:[] as $sel}
  <div class="form-group">
    <label>{$sel.label|escape:'html':'UTF-8'}</label>
    <select name="{$sel.name|escape:'html':'UTF-8'}" class="form-control">
      {foreach $sel.options as $ok => $ol}<option value="{$ok|escape:'html':'UTF-8'}"{if $ok == $sel.value} selected{/if}>{$ol|escape:'html':'UTF-8'}</option>{/foreach}
    </select>
  </div>
  {/foreach}
  <button type="submit" class="btn btn-primary"><i class="icon-filter"></i> Apply</button>
  <span class="bxmp-period-label text-muted">{$period_label|escape:'html':'UTF-8'} · {$period_days|intval} days</span>
</form>
