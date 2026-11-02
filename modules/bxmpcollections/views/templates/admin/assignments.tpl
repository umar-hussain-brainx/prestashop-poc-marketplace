<div id="bxmp-assignments">
<div class="row">
  <div class="col-lg-7">
    <div class="panel" id="bxmp-collection-products">
      <div class="panel-heading"><i class="icon-cube"></i> Products of "{$c.name|escape:'html':'UTF-8'}" <span class="badge">{$products|count}</span></div>
      <form method="get" action="index.php" class="form-inline" style="margin-bottom:12px">
        <input type="hidden" name="controller" value="AdminBxmpCollections">
        <input type="hidden" name="token" value="{$token|escape:'html':'UTF-8'}">
        <input type="hidden" name="id_collection" value="{$c.id|intval}">
        <input type="hidden" name="updatebxmp_collection" value="1">
        <input type="text" name="product_search" value="{$search|escape:'html':'UTF-8'}" class="form-control" placeholder="Name, EAN13, reference or ID" style="width:320px" id="bxmp-product-search">
        <button type="submit" class="btn btn-default"><i class="icon-search"></i> Search</button>
      </form>
      {if $search}
        <form method="post" action="{$base_url|escape:'html':'UTF-8'}">
          <input type="hidden" name="bxmp_action" value="add_product">
          <input type="hidden" name="product_search" value="{$search|escape:'html':'UTF-8'}">
          <table class="table table-condensed" id="bxmp-search-results">
            <thead><tr><th></th><th>ID</th><th>Name</th><th>Reference</th><th>EAN13</th><th></th></tr></thead>
            <tbody>
            {foreach $results as $r}
              <tr>
                <td>{if !in_array($r.id_product, $assigned_ids)}<input type="checkbox" name="id_products[]" value="{$r.id_product|intval}">{/if}</td>
                <td>{$r.id_product|intval}</td><td>{$r.name|escape:'html':'UTF-8'}{if !$r.active} <span class="label label-default">inactive</span>{/if}</td>
                <td>{$r.reference|escape:'html':'UTF-8'}</td><td>{$r.ean13|escape:'html':'UTF-8'}</td>
                <td>{if in_array($r.id_product, $assigned_ids)}<span class="label label-success">in collection</span>{else}
                  <button type="submit" name="id_product" value="{$r.id_product|intval}" class="btn btn-default btn-xs" onclick="this.form.querySelectorAll('input[type=checkbox]').forEach(function(c){ c.checked = false; });">Add</button>{/if}</td>
              </tr>
            {foreachelse}
              <tr><td colspan="6" class="text-muted">No product found for "{$search|escape:'html':'UTF-8'}".</td></tr>
            {/foreach}
            </tbody>
          </table>
          {if $results}<button type="submit" class="btn btn-primary btn-sm"><i class="icon-plus"></i> Add selected</button>{/if}
        </form>
        <hr>
      {/if}
      <table class="table" id="bxmp-assigned-products">
        <thead><tr><th>#</th><th>ID</th><th>Name</th><th>Reference</th><th>EAN13</th><th></th></tr></thead>
        <tbody>
        {foreach $products as $i => $p}
          <tr>
            <td>{$i + 1}</td><td>{$p.id_product|intval}</td><td>{$p.name|escape:'html':'UTF-8'}{if !$p.active} <span class="label label-default">inactive</span>{/if}</td>
            <td>{$p.reference|escape:'html':'UTF-8'}</td><td>{$p.ean13|escape:'html':'UTF-8'}</td>
            <td class="text-right">
              <form method="post" action="{$base_url|escape:'html':'UTF-8'}" style="display:inline">
                <input type="hidden" name="id_product" value="{$p.id_product|intval}">
                <button type="submit" name="bxmp_action" value="move_product" class="btn btn-default btn-xs" onclick="this.form.direction.value=-1" title="Up"><i class="icon-arrow-up"></i></button>
                <button type="submit" name="bxmp_action" value="move_product" class="btn btn-default btn-xs" onclick="this.form.direction.value=1" title="Down"><i class="icon-arrow-down"></i></button>
                <input type="hidden" name="direction" value="1">
                <button type="submit" name="bxmp_action" value="remove_product" class="btn btn-danger btn-xs"><i class="icon-trash"></i> Remove</button>
              </form>
            </td>
          </tr>
        {foreachelse}
          <tr><td colspan="6" class="text-muted">No explicit product. Search above or assign whole categories.</td></tr>
        {/foreach}
        </tbody>
      </table>
    </div>
    <form method="post" action="{$base_url|escape:'html':'UTF-8'}" class="panel" id="bxmp-collection-categories-form">
      <div class="panel-heading"><i class="icon-folder"></i> Whole categories</div>
      {$category_tree nofilter}
      <div class="panel-footer"><button type="submit" name="bxmp_action" value="save_categories" class="btn btn-default pull-right"><i class="process-icon-save"></i> Save categories</button></div>
    </form>
  </div>
  <div class="col-lg-5">
    <div class="panel" id="bxmp-collection-preview">
      <div class="panel-heading"><i class="icon-eye"></i> Preview — {$resolved_count|intval} resolved product(s)
        <span class="label label-{if $c.status == 'live'}success{elseif $c.status == 'scheduled'}info{elseif $c.status == 'expired'}warning{else}danger{/if}">{$c.status|escape:'html':'UTF-8'}</span></div>
      <p><a class="btn btn-default" href="{$preview_url|escape:'html':'UTF-8'}" target="_blank"><i class="icon-external-link"></i> Open {if $c.status != 'live'}preview of {/if}/collection/{$c.slug|escape:'html':'UTF-8'}</a></p>
      <p class="text-muted small">Explicit products first, then the products of the selected categories (and their sub-categories), active and visible only, without duplicates.</p>
      <ol>
        {foreach $resolved as $p}<li>{$p.name|escape:'html':'UTF-8'} <small class="text-muted">{$p.reference|escape:'html':'UTF-8'}</small></li>{/foreach}
      </ol>
      {if $resolved_count > 100}<p class="text-muted">… and {($resolved_count - 100)|intval} more.</p>{/if}
    </div>
  </div>
</div>
</div>
