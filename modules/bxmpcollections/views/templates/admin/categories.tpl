<div id="bxmp-categories">
<div class="row">
  <div class="col-lg-8">
    <div class="panel">
      <div class="panel-heading"><i class="icon-sitemap"></i> Marketplace category tree <span class="badge">{$tree|count}</span>
        <span class="panel-heading-action"><a class="btn btn-default btn-sm" href="{$native_url|escape:'html':'UTF-8'}">Native category editor</a></span></div>
      <p class="text-muted">Categories are native PrestaShop categories (Category ObjectModel): they drive the catalog navigation, the CSV import mapping and whole-category collections.</p>
      <table class="table table-condensed" id="bxmp-category-tree">
        <thead><tr><th>ID</th><th>Category</th><th class="text-center">Products</th><th class="text-center">Mappings</th><th>Status</th><th>Rename</th><th>Move under</th></tr></thead>
        <tbody>
        {foreach $tree as $c}
          <tr data-id="{$c.id_category|intval}"{if !$c.active} class="text-muted"{/if}>
            <td>{$c.id_category|intval}</td>
            <td style="padding-left:{($c.level_depth * 18 + 8)|intval}px">{if $c.level_depth > 0}└ {/if}<strong>{$c.name|escape:'html':'UTF-8'}</strong></td>
            <td class="text-center">{$c.products|intval}</td>
            <td class="text-center">{$c.mappings|intval}</td>
            {if $c.is_root || $c.is_home}
              <td><span class="label label-default">{if $c.is_root}root{else}home{/if}</span></td><td></td><td></td>
            {else}
              <td>
                <form method="post" action="{$action_url|escape:'html':'UTF-8'}" style="display:inline">
                  <input type="hidden" name="id_category" value="{$c.id_category|intval}">
                  <button type="submit" name="bxmp_cat_action" value="toggle" class="btn btn-xs {if $c.active}btn-success{else}btn-danger{/if}" title="Toggle">{if $c.active}active{else}inactive{/if}</button>
                </form>
              </td>
              <td>
                <form method="post" action="{$action_url|escape:'html':'UTF-8'}" class="form-inline">
                  <input type="hidden" name="id_category" value="{$c.id_category|intval}">
                  <input type="text" name="name" value="{$c.name|escape:'html':'UTF-8'}" class="form-control input-sm" style="width:150px">
                  <button type="submit" name="bxmp_cat_action" value="rename" class="btn btn-default btn-xs">Rename</button>
                </form>
              </td>
              <td>
                <form method="post" action="{$action_url|escape:'html':'UTF-8'}" class="form-inline">
                  <input type="hidden" name="id_category" value="{$c.id_category|intval}">
                  <select name="id_parent" class="form-control input-sm" style="width:150px">
                    {foreach $parents as $p}
                      {if !($p.nleft >= $c.nleft && $p.nright <= $c.nright)}<option value="{$p.id_category|intval}"{if $p.id_category == $c.id_parent} selected{/if}>{$p.indent} {$p.name|escape:'html':'UTF-8'}</option>{/if}
                    {/foreach}
                  </select>
                  <button type="submit" name="bxmp_cat_action" value="move" class="btn btn-default btn-xs">Move</button>
                </form>
              </td>
            {/if}
          </tr>
        {/foreach}
        </tbody>
      </table>
    </div>
  </div>
  <div class="col-lg-4">
    <form method="post" action="{$action_url|escape:'html':'UTF-8'}" class="panel" id="bxmp-category-create">
      <div class="panel-heading"><i class="icon-plus"></i> New category</div>
      <div class="form-group"><label>Name</label><input type="text" name="name" class="form-control" required></div>
      <div class="form-group"><label>Parent</label>
        <select name="id_parent" class="form-control">{foreach $parents as $p}<option value="{$p.id_category|intval}"{if $p.is_home} selected{/if}>{$p.indent} {$p.name|escape:'html':'UTF-8'}</option>{/foreach}</select></div>
      <div class="checkbox"><label><input type="checkbox" name="active" value="1" checked> Active</label></div>
      <button type="submit" name="bxmp_cat_action" value="create" class="btn btn-primary"><i class="icon-plus"></i> Create</button>
    </form>

    <div class="panel" id="bxmp-category-maps">
      <div class="panel-heading"><i class="icon-exchange"></i> Vendor category mapping <span class="badge">{$maps|count}</span></div>
      <p class="text-muted small">Maps a category label found in vendor feeds (e.g. "Tazze &amp; Mug") to a marketplace category. Vendor-specific mappings win over global ones. Used by the CSV importer through <code>CategoryMapRepository::resolve($label, $idVendor)</code>.</p>
      <table class="table table-condensed">
        <thead><tr><th>Vendor</th><th>Label</th><th>Category</th><th></th></tr></thead>
        <tbody>
        {foreach $maps as $m}
          <tr><td>{if $m.id_vendor}{$m.vendor_name|escape:'html':'UTF-8'}{else}<em>all</em>{/if}</td><td>{$m.label|escape:'html':'UTF-8'}</td><td>{$m.category_name|default:'(deleted)'|escape:'html':'UTF-8'}</td>
            <td><form method="post" action="{$action_url|escape:'html':'UTF-8'}"><input type="hidden" name="id_category_map" value="{$m.id_category_map|intval}">
              <button type="submit" name="bxmp_cat_action" value="map_delete" class="btn btn-danger btn-xs"><i class="icon-trash"></i></button></form></td></tr>
        {foreachelse}
          <tr><td colspan="4" class="text-muted">No mapping yet.</td></tr>
        {/foreach}
        </tbody>
      </table>
      <form method="post" action="{$action_url|escape:'html':'UTF-8'}">
        <div class="form-group"><label>Vendor label</label><input type="text" name="label" class="form-control" required></div>
        <div class="form-group"><label>Vendor</label><select name="map_id_vendor" class="form-control"><option value="0">All vendors</option>{foreach $vendors as $vid => $vn}<option value="{$vid|intval}">{$vn|escape:'html':'UTF-8'}</option>{/foreach}</select></div>
        <div class="form-group"><label>Category</label><select name="map_id_category" class="form-control">{foreach $parents as $p}{if !$p.is_home}<option value="{$p.id_category|intval}">{$p.indent} {$p.name|escape:'html':'UTF-8'}</option>{/if}{/foreach}</select></div>
        <button type="submit" name="bxmp_cat_action" value="map_save" class="btn btn-default"><i class="icon-save"></i> Save mapping</button>
      </form>
      <hr>
      <form method="get" action="index.php" class="form-inline">
        <input type="hidden" name="controller" value="AdminBxmpCategories"><input type="hidden" name="token" value="{$token|escape:'html':'UTF-8'}">
        <input type="text" name="test_label" class="form-control input-sm" placeholder="Test a label" value="{if $test}{$test.label|escape:'html':'UTF-8'}{/if}">
        <select name="test_vendor" class="form-control input-sm"><option value="0">any vendor</option>{foreach $vendors as $vid => $vn}<option value="{$vid|intval}">{$vn|escape:'html':'UTF-8'}</option>{/foreach}</select>
        <button type="submit" class="btn btn-default btn-sm">Resolve</button>
      </form>
      {if $test}<p id="bxmp-map-test" style="margin-top:8px">"{$test.label|escape:'html':'UTF-8'}" (key <code>{$test.key|escape:'html':'UTF-8'}</code>) →
        {if $test.id_category}<strong>{$test.name|escape:'html':'UTF-8'}</strong> (#{$test.id_category|intval}){else}<span class="text-danger">no mapping</span>{/if}</p>{/if}
    </div>
  </div>
</div>
</div>
