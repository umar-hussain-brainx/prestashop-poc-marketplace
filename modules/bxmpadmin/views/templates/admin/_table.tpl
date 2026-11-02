<div class="panel bxmp-table">
  <div class="panel-heading">
    <i class="{$icon|escape:'html':'UTF-8'}"></i> {$title|escape:'html':'UTF-8'} <span class="badge">{$rows|count}</span>
    {if $export_url}<span class="panel-heading-action"><a class="btn btn-default btn-sm bxmp-export" href="{$export_url|escape:'html':'UTF-8'}"><i class="icon-download"></i> CSV</a></span>{/if}
  </div>
  <div class="table-responsive">
  <table class="table table-condensed">
    <thead><tr>{foreach $columns as $key => $col}<th class="{if isset($col[1]) && in_array($col[1], ['money','pct','int','num'])}text-right{/if}">{$col[0]|escape:'html':'UTF-8'}</th>{/foreach}</tr></thead>
    <tbody>
    {foreach $rows as $row}
      <tr{if !empty($row._class)} class="{$row._class|escape:'html':'UTF-8'}"{/if}>
      {foreach $columns as $key => $col}
        {assign var=type value=$col[1]|default:'text'}
        {assign var=v value=$row[$key]|default:null}
        {if $type == 'money'}<td class="text-right">{if $v === null || $v === ''}–{else}{$v|string_format:'%.2f'} {$bxmp_currency}{/if}</td>
        {elseif $type == 'pct'}<td class="text-right">{if $v === null || $v === ''}–{else}{$v|string_format:'%.1f'} %{/if}</td>
        {elseif $type == 'int'}<td class="text-right">{$v|intval}</td>
        {elseif $type == 'num'}<td class="text-right">{if $v === null || $v === ''}–{else}{$v|escape:'html':'UTF-8'}{/if}</td>
        {elseif $type == 'html'}<td>{$v nofilter}</td>
        {elseif $type == 'bool'}<td>{if $v}<i class="icon-check text-success"></i>{else}<i class="icon-remove text-danger"></i>{/if}</td>
        {else}<td>{$v|escape:'html':'UTF-8'}</td>{/if}
      {/foreach}
      </tr>
    {foreachelse}
      <tr><td colspan="{$columns|count}" class="text-center text-muted">{$empty|escape:'html':'UTF-8'}</td></tr>
    {/foreach}
    </tbody>
    {if $footer}
    <tfoot><tr class="bxmp-total">
      {foreach $columns as $key => $col}
        {assign var=type value=$col[1]|default:'text'}
        {assign var=v value=$footer[$key]|default:null}
        {if $v === null}<td></td>
        {elseif $type == 'money'}<td class="text-right"><strong>{$v|string_format:'%.2f'} {$bxmp_currency}</strong></td>
        {elseif $type == 'pct'}<td class="text-right"><strong>{$v|string_format:'%.1f'} %</strong></td>
        {elseif $type == 'int'}<td class="text-right"><strong>{$v|intval}</strong></td>
        {else}<td><strong>{$v|escape:'html':'UTF-8'}</strong></td>{/if}
      {/foreach}
    </tr></tfoot>
    {/if}
  </table>
  </div>
</div>
