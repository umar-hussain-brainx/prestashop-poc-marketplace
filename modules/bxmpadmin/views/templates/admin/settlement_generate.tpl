<form method="post" action="{$action|escape:'html':'UTF-8'}" class="panel form-inline bxmp-filters" id="bxmp-generate">
  <div class="panel-heading" style="width:100%"><i class="icon-plus"></i> Generate settlement</div>
  <div class="form-group">
    <label>Vendor</label>
    <select name="gen_vendor" class="form-control">
      <option value="0">All vendors (one settlement each)</option>
      {foreach $vendors as $vid => $vname}<option value="{$vid|intval}">{$vname|escape:'html':'UTF-8'}</option>{/foreach}
    </select>
  </div>
  <div class="form-group">
    <label>From</label> <input type="date" name="gen_from" value="{$from|escape:'html':'UTF-8'}" class="form-control" required>
    <label>To</label> <input type="date" name="gen_to" value="{$to|escape:'html':'UTF-8'}" class="form-control" required>
  </div>
  <button type="submit" name="submitBxmpGenerate" value="1" class="btn btn-primary"><i class="icon-cogs"></i> Generate draft</button>
  <span class="text-muted">Default: last month. Re-generating the same vendor + period refreshes its draft; periods overlapping an approved/paid settlement are refused.
    <a href="#" onclick="var f=document.getElementById('bxmp-generate');f.gen_from.value='{$month_from|escape:'javascript':'UTF-8'}';f.gen_to.value='{$today|escape:'javascript':'UTF-8'}';return false;">This month to date</a></span>
</form>
